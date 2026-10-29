<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Enums\CartStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\RecoveryEventType;
use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;
use App\Events\Automation\RecoveryOpportunityDetected as AutomationRecoveryOpportunityDetected;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RecoveryOpportunity;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds lost-sale opportunities from signals Dukavo already owns.
 *
 * Two signals are supported, both deliberately narrow:
 *
 *  - a stale cart with real lines that never became an order;
 *  - a customer's last message has no later outbound reply.
 *
 * A quiet conversation on its own is *not* a lost sale. Closed threads, customers
 * who opted out, customers with an order, and carts that are still fresh are all
 * excluded. Detection is idempotent through a stable dedupe key.
 */
final class RecoveryDetectionService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RecoveryEventService $events,
    ) {}

    /**
     * @return array{created: int, expired: int, reopened: int, skipped: int, failed: int}
     */
    public function detectForTenant(Tenant $tenant, bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        return $this->tenants->run($tenant, function () use ($dryRun, $now): array {
            $now = $now !== null ? Carbon::instance($now) : now();

            $stats = ['created' => 0, 'expired' => 0, 'reopened' => 0, 'skipped' => 0, 'failed' => 0];

            if (! (bool) config('recovery.enabled', true)) {
                return $stats;
            }

            if (! $dryRun) {
                $stats['reopened'] = $this->wakeDueSnoozes($now);
                $stats['expired'] = $this->expireStale($now);
            }

            $limit = max(1, (int) config('recovery.max_per_run', 500));

            $this->detectCarts($now, $limit, $dryRun, $stats);
            $this->detectUnansweredInquiries($now, $limit, $dryRun, $stats);

            return $stats;
        });
    }

    private function wakeDueSnoozes(Carbon $now): int
    {
        $reopened = 0;

        RecoveryOpportunity::query()
            ->withStatus(RecoveryOpportunityStatus::Snoozed)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $now)
            ->orderBy('next_follow_up_at')
            ->limit(max(1, (int) config('recovery.max_per_run', 500)))
            ->get()
            ->each(function (RecoveryOpportunity $opportunity) use ($now, &$reopened): void {
                $opportunity->forceFill([
                    'status' => RecoveryOpportunityStatus::Eligible,
                    'next_follow_up_at' => null,
                ])->save();

                $this->events->record(
                    $opportunity,
                    RecoveryEventType::Reopened,
                    note: 'Snooze elapsed; the opportunity is ready again.',
                    meta: ['reopened_at' => $now->toIso8601String()],
                );

                $reopened++;
            });

        return $reopened;
    }

    private function expireStale(Carbon $now): int
    {
        $expired = 0;

        RecoveryOpportunity::query()
            ->open()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->limit(max(1, (int) config('recovery.max_per_run', 500)))
            ->get()
            ->each(function (RecoveryOpportunity $opportunity) use ($now, &$expired): void {
                $opportunity->forceFill([
                    'status' => RecoveryOpportunityStatus::Expired,
                ])->save();

                $this->events->record(
                    $opportunity,
                    RecoveryEventType::Expired,
                    note: 'Expired after '.max(1, (int) config('recovery.expires_after_days', 30)).' days without recovery.',
                    meta: ['expired_at' => $now->toIso8601String()],
                );

                $expired++;
            });

        return $expired;
    }

    /**
     * @param  array{created: int, expired: int, reopened: int, skipped: int, failed: int}  $stats
     */
    private function detectCarts(Carbon $now, int $limit, bool $dryRun, array &$stats): void
    {
        $staleBefore = $now->copy()->subHours(max(1, (int) config('recovery.stale_after_hours', 24)));
        $lookbackAfter = $now->copy()->subDays(max(1, (int) config('recovery.lookback_days', 90)));

        $carts = Cart::query()
            ->with(['conversation.customer', 'customer', 'items'])
            ->whereIn('status', [CartStatus::Draft, CartStatus::PendingConfirmation])
            ->whereNull('order_id')
            ->where('updated_at', '<=', $staleBefore)
            ->where('updated_at', '>=', $lookbackAfter)
            ->whereHas('items')
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($carts as $cart) {
            try {
                $conversation = $cart->conversation;

                if (! $this->conversationIsEligible($conversation)) {
                    $stats['skipped']++;

                    continue;
                }

                $customer = $cart->customer ?? $conversation->customer;

                if (! $this->customerIsEligible($customer)) {
                    $stats['skipped']++;

                    continue;
                }

                if ($this->hasOrder($conversation)) {
                    $stats['skipped']++;

                    continue;
                }

                $reason = $cart->status === CartStatus::PendingConfirmation
                    ? RecoveryReason::UnconfirmedQuote
                    : RecoveryReason::AbandonedCart;

                $attributes = [
                    'store_id' => $cart->store_id,
                    'conversation_id' => $conversation->getKey(),
                    'customer_id' => $customer?->getKey(),
                    'cart_id' => $cart->getKey(),
                    'reason' => $reason,
                    'estimated_value' => round((float) $cart->grand_total, 2),
                    'currency' => $cart->currency,
                    'last_activity_at' => $cart->updated_at,
                    'meta' => [
                        'cart_status' => $cart->status->value,
                        'cart_version' => $cart->version,
                        'item_count' => $cart->items->sum('quantity'),
                    ],
                ];

                $created = $this->createOpportunity(
                    dedupeKey: 'cart:'.$cart->getKey(),
                    now: $now,
                    dryRun: $dryRun,
                    attributes: $attributes,
                    stats: $stats,
                );

                if ($created) {
                    $stats['created']++;
                }
            } catch (Throwable $exception) {
                $stats['failed']++;
                report($exception);
            }
        }
    }

    /**
     * @param  array{created: int, expired: int, reopened: int, skipped: int, failed: int}  $stats
     */
    private function detectUnansweredInquiries(Carbon $now, int $limit, bool $dryRun, array &$stats): void
    {
        $staleBefore = $now->copy()->subHours(max(1, (int) config('recovery.stale_after_hours', 24)));
        $lookbackAfter = $now->copy()->subDays(max(1, (int) config('recovery.lookback_days', 90)));

        $conversations = Conversation::query()
            ->with(['customer', 'latestInboundMessage'])
            ->whereIn('status', [ConversationStatus::Open, ConversationStatus::Pending])
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', $staleBefore)
            ->where('last_inbound_at', '>=', $lookbackAfter)
            ->whereDoesntHave('messages', function ($query): void {
                $query->where('direction', MessageDirection::Outbound)
                    ->where('is_internal', false)
                    ->whereNotIn('type', [MessageType::Note->value, MessageType::System->value])
                    ->whereColumn('conversation_messages.sent_at', '>', 'conversations.last_inbound_at');
            })
            ->whereDoesntHave('carts', fn ($query) => $query->whereIn('status', [
                CartStatus::Draft->value,
                CartStatus::PendingConfirmation->value,
            ]))
            ->whereDoesntHave('orders')
            ->orderBy('last_inbound_at')
            ->limit($limit)
            ->get();

        foreach ($conversations as $conversation) {
            try {
                if (! $this->customerIsEligible($conversation->customer)) {
                    $stats['skipped']++;

                    continue;
                }

                $message = $conversation->latestInboundMessage;

                if ($message === null) {
                    $stats['skipped']++;

                    continue;
                }

                $attributes = [
                    'store_id' => $conversation->store_id,
                    'conversation_id' => $conversation->getKey(),
                    'customer_id' => $conversation->customer_id,
                    'cart_id' => null,
                    'reason' => RecoveryReason::UnansweredInquiry,
                    'estimated_value' => null,
                    'currency' => null,
                    'last_activity_at' => $conversation->last_inbound_at,
                    'meta' => [
                        'last_inbound_message_id' => $message->getKey(),
                        'inquiry_preview' => mb_substr(trim((string) $message->body), 0, 280),
                    ],
                ];

                $created = $this->createOpportunity(
                    dedupeKey: 'conversation:'.$conversation->getKey().':inbound:'.$message->getKey(),
                    now: $now,
                    dryRun: $dryRun,
                    attributes: $attributes,
                    stats: $stats,
                );

                if ($created) {
                    $stats['created']++;
                }
            } catch (Throwable $exception) {
                $stats['failed']++;
                report($exception);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{created: int, expired: int, reopened: int, skipped: int, failed: int}  $stats
     */
    private function createOpportunity(
        string $dedupeKey,
        Carbon $now,
        bool $dryRun,
        array $attributes,
        array &$stats,
    ): bool {
        $existing = RecoveryOpportunity::query()
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing instanceof RecoveryOpportunity) {
            // A final record is a human decision (or a completed recovery) and
            // is never reopened by a later detection run.
            if ($existing->status->isOpen()) {
                $existing->forceFill([
                    'estimated_value' => $attributes['estimated_value'],
                    'currency' => $attributes['currency'],
                    'last_activity_at' => $attributes['last_activity_at'],
                    'reason' => $attributes['reason'],
                    'meta' => array_merge($existing->meta ?? [], $attributes['meta'] ?? []),
                ])->save();
            }

            $stats['skipped']++;

            return false;
        }

        if ($dryRun) {
            return true;
        }

        DB::transaction(function () use ($dedupeKey, $now, $attributes): void {
            $opportunity = RecoveryOpportunity::query()->create($attributes + [
                'dedupe_key' => $dedupeKey,
                'status' => RecoveryOpportunityStatus::Eligible,
                'detected_at' => $now,
                'expires_at' => $now->copy()->addDays(max(1, (int) config('recovery.expires_after_days', 30))),
            ]);

            $this->events->record(
                $opportunity,
                RecoveryEventType::Detected,
                meta: ['reason' => $opportunity->reason->value],
            );

            DB::afterCommit(fn () => event(AutomationRecoveryOpportunityDetected::fromOpportunity($opportunity)));
        });

        return true;
    }

    private function conversationIsEligible(?Conversation $conversation): bool
    {
        return $conversation instanceof Conversation
            && $conversation->status !== ConversationStatus::Closed
            && $conversation->customer_id !== null;
    }

    private function customerIsEligible(?Customer $customer): bool
    {
        return $customer !== null
            && $customer->status === 'active'
            && $customer->whatsapp_opt_out_at === null;
    }

    private function hasOrder(Conversation $conversation): bool
    {
        return Order::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('status', '!=', OrderStatus::Cancelled)
            ->exists();
    }
}
