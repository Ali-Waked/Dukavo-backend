<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversations and their messages.
 *
 * Conversations are stored once per tenant and reused across channels: the same
 * customer contacting Dukavo on WhatsApp and, later, on the web should land in
 * one thread. Channel-specific payloads are kept in the `meta` JSON column and
 * never promoted to columns unless every channel agrees on their meaning.
 */
class ConversationService
{
    /**
     * Find the open thread for a channel identity, or start a new one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openForCustomer(Customer $customer, string $channel, string $channelAccount, array $attributes = []): Conversation
    {
        $existing = Conversation::query()
            ->where('customer_id', $customer->getKey())
            ->where('channel', $channel)
            ->where('channel_account', $channelAccount)
            ->withStatus(ConversationStatus::Open)
            ->latest('last_message_at')
            ->first();

        if ($existing !== null) {
            $existing->forceFill(['last_message_at' => now()])->save();

            return $existing;
        }

        return DB::transaction(function () use ($customer, $channel, $channelAccount, $attributes): Conversation {
            $conversation = Conversation::query()->create([
                'customer_id' => $customer->getKey(),
                'channel' => $channel,
                'channel_account' => $channelAccount,
                'subject' => $attributes['subject'] ?? null,
                'status' => ConversationStatus::Open,
                'assigned_to' => $attributes['assigned_to'] ?? null,
            ]);

            // `last_message_at` and `meta` are maintained by the service, so they
            // are written explicitly rather than exposed to mass assignment.
            $conversation->forceFill([
                'last_message_at' => now(),
                'meta' => $attributes['meta'] ?? null,
            ])->save();

            return $conversation;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Conversation $conversation, array $attributes): Conversation
    {
        $conversation->fill($attributes)->save();

        return $conversation;
    }

    public function assign(Conversation $conversation, int $userId): Conversation
    {
        $conversation->forceFill(['assigned_to' => $userId])->save();

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function changeStatus(Conversation $conversation, ConversationStatus $status): Conversation
    {
        $conversation->forceFill([
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
        ])->save();

        return $conversation;
    }

    /**
     * Append a message to a thread.
     *
     * Idempotency depends on the provider's own identifier. When one is supplied —
     * which the webhook always does — the message is matched on the unique
     * (conversation, direction, external_id) index, so a redelivered webhook
     * updates the existing row instead of appending a second copy. When no
     * identifier exists there is nothing to match on: NULLs are distinct in that
     * index on both MySQL and SQLite, so a row is simply appended. Callers that
     * can be retried must therefore pass an `external_id`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addMessage(Conversation $conversation, MessageDirection $direction, array $attributes): ConversationMessage
    {
        return DB::transaction(function () use ($conversation, $direction, $attributes): ConversationMessage {
            $values = [
                'user_id' => $attributes['user_id'] ?? null,
                'type' => $attributes['type'] ?? MessageType::Text,
                'body' => $attributes['body'] ?? null,
                'meta' => $attributes['meta'] ?? null,
                'delivery_status' => $attributes['delivery_status'] ?? ($direction === MessageDirection::Outbound ? 'sent' : 'received'),
                'sent_at' => $attributes['sent_at'] ?? now(),
            ];

            $externalId = $attributes['external_id'] ?? null;

            $message = $externalId === null
                ? ConversationMessage::query()->create([
                    'conversation_id' => $conversation->getKey(),
                    'direction' => $direction,
                    'external_id' => null,
                ] + $values)
                : ConversationMessage::query()->updateOrCreate(
                    [
                        'conversation_id' => $conversation->getKey(),
                        'direction' => $direction,
                        'external_id' => $externalId,
                    ],
                    $values,
                );

            $this->touch($conversation);

            return $message;
        });
    }

    /**
     * Record a delivery receipt reported by the channel provider.
     *
     * Webhooks arrive out of order and repeat, so each state is only ever moved
     * forward: a late "delivered" event cannot un-read a message.
     *
     * @param  array<int, string>  $externalIds
     * @param  'delivered'|'read'|'failed'  $state
     */
    public function markDelivery(array $externalIds, string $state, ?string $failureReason = null): int
    {
        if ($externalIds === []) {
            return 0;
        }

        $updates = match ($state) {
            'delivered' => ['delivery_status' => 'delivered', 'delivered_at' => now()],
            'read' => ['delivery_status' => 'read', 'delivered_at' => now(), 'read_at' => now()],
            'failed' => ['delivery_status' => 'failed'],
            default => [],
        };

        if ($updates === []) {
            return 0;
        }

        $query = ConversationMessage::query()
            ->whereIn('external_id', $externalIds)
            ->where('direction', MessageDirection::Outbound);

        // Receipts are not delivered in order, so each state only moves forward.
        // `delivered` and `read` are both confirmations: neither may be undone by
        // a late failure, and neither may be repeated as a downgrade. A failure may
        // only be recorded against a message the provider has not confirmed.
        $confirmed = ['delivered', 'read'];

        $query->where(function ($inner) use ($confirmed, $state): void {
            $inner->whereNull('delivery_status')
                ->when(
                    $state === 'read',
                    // Everything except `read` itself can still become read.
                    fn ($unconfirmed) => $unconfirmed->orWhere('delivery_status', '!=', 'read'),
                    fn ($unconfirmed) => $unconfirmed->orWhereNotIn('delivery_status', $confirmed),
                );
        });

        if ($failureReason === null) {
            return $query->update($updates);
        }

        // A bulk update cannot merge JSON, so the reason is written per row and
        // added alongside the existing payload rather than replacing it.
        $updated = 0;

        foreach ($query->get() as $message) {
            $message->forceFill($updates + [
                'meta' => array_merge($message->meta ?? [], ['error' => $failureReason]),
            ])->save();

            $updated++;
        }

        return $updated;
    }

    /**
     * Threads awaiting a reply, newest first.
     *
     * @return Collection<int, Conversation>
     */
    public function inbox(?int $assigneeId = null, int $limit = 25): Collection
    {
        return Conversation::query()
            ->with(['customer', 'assignee', 'latestMessage'])
            ->withStatus(ConversationStatus::Open)
            ->when($assigneeId, fn ($query) => $query->where('assigned_to', $assigneeId))
            ->orderByDesc('last_message_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Update denormalised thread metadata used by the inbox list.
     */
    private function touch(Conversation $conversation): void
    {
        $conversation->forceFill(['last_message_at' => now()])->save();
    }
}
