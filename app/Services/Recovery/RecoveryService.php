<?php

declare(strict_types=1);

namespace App\Services\Recovery;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\RecoveryEventType;
use App\Enums\RecoveryOpportunityStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\RecoveryOpportunity;
use App\Models\User;
use App\Services\Channels\WhatsApp\Contracts\WhatsAppGateway;
use App\Services\Conversations\ConversationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Human-driven recovery work.
 *
 * Every outreach is explicit: an agent reviews the draft and presses send. We
 * refuse to contact an opted-out customer or a closed thread, and a WhatsApp
 * free-form text is only allowed inside the provider's reply window. Templates
 * remain available outside it, exactly like the inbox.
 */
final class RecoveryService
{
    /**
     * Exact, case-insensitive opt-out phrases. Loose matching would let a
     * message such as "stop by tomorrow" silently block a customer, so we only
     * act on unambiguous commands.
     *
     * @var list<string>
     */
    private const OPT_OUT_PHRASES = [
        'stop', 'stop messaging', 'stop messages', 'unsubscribe', 'do not contact',
        'do not message', 'no messages', 'opt out',
        'إلغاء الاشتراك', 'لا ترسل', 'توقف', 'إيقاف الرسائل',
    ];

    public function __construct(
        private readonly WhatsAppGateway $gateway,
        private readonly ConversationService $conversations,
        private readonly RecoveryEventService $events,
    ) {}

    /**
     * @param  array{mode: string, body?: string|null, template_name?: string|null, language_code?: string|null, parameters?: array<int, string>|null}  $payload
     */
    public function contact(RecoveryOpportunity $opportunity, User $actor, array $payload): RecoveryOpportunity
    {
        $this->assertContactable($opportunity);

        $conversation = $opportunity->conversation()->firstOrFail();
        $mode = $payload['mode'] ?? 'text';
        $attempt = (int) $opportunity->contact_attempts + 1;
        $idempotencyKey = 'recovery:'.$opportunity->getKey().':contact:'.$attempt;

        if ($mode === 'template') {
            if ($conversation->channel !== 'whatsapp') {
                throw BusinessRuleViolation::because('Templates are only available on WhatsApp conversations.');
            }

            $providerId = $this->gateway->sendTemplate(
                $conversation,
                (string) ($payload['template_name'] ?? ''),
                (string) ($payload['language_code'] ?? ''),
                array_values($payload['parameters'] ?? []),
                $idempotencyKey,
            );
        } else {
            $this->conversations->assertTextReplyAllowed($conversation);

            $providerId = $this->gateway->sendText(
                $conversation,
                (string) ($payload['body'] ?? ''),
                $idempotencyKey,
            );
        }

        $message = ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('external_id', $providerId)
            ->first();

        if (! $message instanceof ConversationMessage) {
            throw new RuntimeException('The provider accepted the recovery message but it was not found in the conversation.');
        }

        $updated = DB::transaction(function () use ($opportunity, $actor, $message, $mode, $attempt): RecoveryOpportunity {
            $opportunity->forceFill([
                'status' => RecoveryOpportunityStatus::Contacted,
                'last_contacted_at' => now(),
                'contact_attempts' => $attempt,
                'next_follow_up_at' => now()->addDays(max(1, (int) config('recovery.follow_up_after_days', 3))),
                'last_activity_at' => now(),
            ])->save();

            $this->events->record(
                $opportunity,
                RecoveryEventType::Contacted,
                actor: $actor,
                message: $message,
                meta: ['mode' => $mode, 'attempt' => $attempt],
            );

            return $opportunity->refresh();
        });

        return $updated->load(['conversation.customer', 'customer', 'cart', 'order', 'assignee', 'events.actor']);
    }

    public function assign(RecoveryOpportunity $opportunity, ?User $assignee, User $actor): RecoveryOpportunity
    {
        if ($opportunity->status->isFinal()) {
            throw BusinessRuleViolation::because('A closed opportunity cannot be reassigned.');
        }

        if ($assignee !== null && ! $assignee->isActive()) {
            throw BusinessRuleViolation::because('Only an active team member can own a recovery opportunity.');
        }

        $opportunity->forceFill(['assigned_to' => $assignee?->getKey()])->save();

        $this->events->record(
            $opportunity,
            RecoveryEventType::Assigned,
            actor: $actor,
            note: $assignee !== null ? 'Assigned to '.$assignee->name : 'Unassigned',
        );

        return $opportunity->refresh()->load(['assignee', 'events.actor']);
    }

    public function snooze(RecoveryOpportunity $opportunity, CarbonInterface $until, User $actor): RecoveryOpportunity
    {
        $this->assertOpen($opportunity);

        $until = Carbon::instance($until);

        if ($until->isPast()) {
            throw BusinessRuleViolation::because('Choose a future time to snooze this opportunity.');
        }

        $opportunity->forceFill([
            'status' => RecoveryOpportunityStatus::Snoozed,
            'next_follow_up_at' => $until,
        ])->save();

        $this->events->record(
            $opportunity,
            RecoveryEventType::Snoozed,
            actor: $actor,
            meta: ['until' => $until->toIso8601String()],
        );

        return $opportunity->refresh();
    }

    public function dismiss(RecoveryOpportunity $opportunity, User $actor, ?string $reason = null): RecoveryOpportunity
    {
        $this->assertOpen($opportunity);

        $opportunity->forceFill([
            'status' => RecoveryOpportunityStatus::Dismissed,
            'dismissed_by' => $actor->getKey(),
            'dismissed_at' => now(),
            'dismissal_reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        ])->save();

        $this->events->record(
            $opportunity,
            RecoveryEventType::Dismissed,
            actor: $actor,
            note: $reason,
        );

        return $opportunity->refresh();
    }

    /**
     * The customer asked not to be contacted. This is a tenant-wide consent
     * stamp, not a per-opportunity flag, and it is never reversed by detection.
     */
    public function doNotContact(Customer $customer, ?User $actor = null, ?string $reason = null): int
    {
        $customer->forceFill(['whatsapp_opt_out_at' => $customer->whatsapp_opt_out_at ?? now()])->save();

        $affected = 0;

        $opportunities = RecoveryOpportunity::query()
            ->where('customer_id', $customer->getKey())
            ->open()
            ->get();

        foreach ($opportunities as $opportunity) {
            $opportunity->forceFill([
                'status' => RecoveryOpportunityStatus::Dismissed,
                'dismissed_by' => $actor?->getKey(),
                'dismissed_at' => now(),
                'dismissal_reason' => $reason ?? 'Customer asked not to be contacted.',
            ])->save();

            $this->events->record(
                $opportunity,
                RecoveryEventType::OptedOut,
                actor: $actor,
                note: $reason,
            );

            $affected++;
        }

        return $affected;
    }

    /**
     * A customer replied. This is a funnel signal only; the opportunity remains
     * open until an order is actually placed or an agent closes it.
     */
    public function handleInboundMessage(ConversationMessage $message): void
    {
        if ($message->direction !== MessageDirection::Inbound || $message->is_internal) {
            return;
        }

        $conversation = Conversation::query()->with('customer')->find($message->conversation_id);

        if (! $conversation instanceof Conversation) {
            return;
        }

        if ($this->isOptOut($message)) {
            if ($conversation->customer instanceof Customer) {
                $this->doNotContact($conversation->customer, reason: 'Customer sent an opt-out keyword.');
            }

            return;
        }

        RecoveryOpportunity::query()
            ->where('conversation_id', $conversation->getKey())
            ->open()
            ->whereNull('responded_at')
            ->get()
            ->each(function (RecoveryOpportunity $opportunity) use ($message): void {
                $opportunity->forceFill([
                    'responded_at' => $message->sent_at ?? now(),
                    'last_activity_at' => $message->sent_at ?? now(),
                ])->save();

                $this->events->record(
                    $opportunity,
                    RecoveryEventType::Responded,
                    message: $message,
                    meta: ['direction' => 'inbound'],
                );
            });
    }

    /**
     * Link a real, non-cancelled order to the best open opportunity in its
     * conversation. The order remains the source of revenue truth.
     */
    public function attributeOrder(Order $order): ?RecoveryOpportunity
    {
        if ($order->conversation_id === null || $order->status === OrderStatus::Cancelled) {
            return null;
        }

        $opportunity = RecoveryOpportunity::query()
            ->where('conversation_id', $order->conversation_id)
            ->open()
            ->whereNull('order_id')
            ->orderByRaw("CASE WHEN status = 'contacted' THEN 0 WHEN status = 'eligible' THEN 1 ELSE 2 END")
            ->orderByDesc('detected_at')
            ->first();

        if (! $opportunity instanceof RecoveryOpportunity) {
            return null;
        }

        DB::transaction(function () use ($opportunity, $order): void {
            $opportunity->forceFill([
                'status' => RecoveryOpportunityStatus::Recovered,
                'order_id' => $order->getKey(),
                'recovered_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            $this->events->record(
                $opportunity,
                RecoveryEventType::Recovered,
                note: 'Order '.$order->order_number.' placed.',
                meta: [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'order_total' => (float) $order->grand_total,
                    'currency' => $order->currency,
                ],
            );

            // The conversation can hold more than one detected signal (for
            // example a stale cart and an unanswered question). Only one order
            // may be credited, so close the duplicates without touching them.
            RecoveryOpportunity::query()
                ->where('conversation_id', $order->conversation_id)
                ->open()
                ->whereKeyNot($opportunity->getKey())
                ->get()
                ->each(function (RecoveryOpportunity $duplicate) use ($order): void {
                    $duplicate->forceFill([
                        'status' => RecoveryOpportunityStatus::Dismissed,
                        'dismissed_at' => now(),
                        'dismissal_reason' => 'Customer placed order '.$order->order_number.'; credited to another opportunity.',
                    ])->save();

                    $this->events->record(
                        $duplicate,
                        RecoveryEventType::Dismissed,
                        note: 'Superseded by order '.$order->order_number.'.',
                        meta: ['order_id' => $order->getKey()],
                    );
                });
        });

        return $opportunity->refresh();
    }

    /**
     * Cancellation removes the claimed revenue. The opportunity reopens so an
     * agent can decide whether a genuine recovery attempt is still useful.
     */
    public function releaseOrder(Order $order): ?RecoveryOpportunity
    {
        $opportunity = RecoveryOpportunity::query()
            ->where('order_id', $order->getKey())
            ->first();

        if (! $opportunity instanceof RecoveryOpportunity) {
            return null;
        }

        $opportunity->forceFill([
            'status' => $opportunity->last_contacted_at !== null
                ? RecoveryOpportunityStatus::Contacted
                : RecoveryOpportunityStatus::Eligible,
            'order_id' => null,
            'recovered_at' => null,
            'last_activity_at' => now(),
        ])->save();

        $this->events->record(
            $opportunity,
            RecoveryEventType::AttributionReleased,
            note: 'Order '.$order->order_number.' was cancelled; recovered revenue removed.',
            meta: ['order_id' => $order->getKey()],
        );

        return $opportunity->refresh();
    }

    private function assertContactable(RecoveryOpportunity $opportunity): void
    {
        $this->assertOpen($opportunity);

        $opportunity->loadMissing(['customer', 'conversation']);

        if ($opportunity->customer?->whatsapp_opt_out_at !== null) {
            throw BusinessRuleViolation::because(
                'This customer asked not to be contacted on WhatsApp.',
                ['customer_id' => $opportunity->customer_id],
                'customer_opted_out',
            );
        }

        if (! $opportunity->conversation instanceof Conversation) {
            throw BusinessRuleViolation::because('The conversation for this opportunity no longer exists.');
        }

        if ($opportunity->conversation->status->isClosed()) {
            throw BusinessRuleViolation::because('Reopen the conversation before contacting this customer.');
        }
    }

    private function assertOpen(RecoveryOpportunity $opportunity): void
    {
        if ($opportunity->status->isFinal()) {
            throw BusinessRuleViolation::because('This recovery opportunity is already closed.');
        }
    }

    private function isOptOut(ConversationMessage $message): bool
    {
        if ($message->type === MessageType::Note || $message->type === MessageType::System) {
            return false;
        }

        $body = strtolower(trim((string) $message->body));
        $body = preg_replace('/[.!?,;:]+$/u', '', $body) ?? $body;

        return in_array($body, self::OPT_OUT_PHRASES, true);
    }
}
