<?php

declare(strict_types=1);

namespace App\Services\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Events\Automation\ConversationMessageReceived as AutomationConversationMessageReceived;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversations and their messages.
 *
 * Channel-neutral business logic lives here; the provider only supplies an
 * identity, a message id and, for WhatsApp, media. Nothing in this service
 * believes a provider event is a business action: the webhook is responsible
 * for claiming an event once, and this service only appends the resulting
 * message or advances an outbound receipt.
 */
class ConversationService
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly TenantContext $tenants,
    ) {}

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
            $existing->forceFill(
                ['last_message_at' => now()] + $this->channelBinding($existing, $attributes),
            )->save();

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
                'display_name' => $attributes['display_name'] ?? null,
                'phone' => $attributes['phone'] ?? null,
            ]);

            // `last_message_at`, `meta` and the channel binding are maintained by
            // the service, so they are written explicitly rather than exposed to
            // mass assignment.
            $conversation->forceFill([
                'last_message_at' => now(),
                'meta' => $attributes['meta'] ?? null,
            ] + $this->channelBinding($conversation, $attributes))->save();

            return $conversation;
        });
    }

    /**
     * Channel bindings a caller may attach to a thread, and only once.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function channelBinding(Conversation $conversation, array $attributes): array
    {
        $binding = [];

        foreach (['whatsapp_account_id', 'store_id'] as $key) {
            if (array_key_exists($key, $attributes)
                && $conversation->getAttribute($key) === null
                && filled($attributes[$key])
            ) {
                $binding[$key] = $attributes[$key];
            }
        }

        return $binding;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Conversation $conversation, array $attributes): Conversation
    {
        $conversation->fill($attributes)->save();

        return $conversation;
    }

    /**
     * Assign a conversation to an active member of this tenant who can read the
     * inbox. Passing NULL unassigns it.
     *
     * The route permission says the caller may assign; this check says the
     * target may receive. Without it a merchant could hand a thread to a user
     * from another tenant, or to someone whose role cannot open it.
     */
    public function assign(Conversation $conversation, ?int $userId): Conversation
    {
        if ($userId === null) {
            $old = $conversation->assigned_to;
            $conversation->forceFill(['assigned_to' => null])->save();

            if ($old !== null) {
                $this->auditConversation($conversation, AuditAction::ConversationAssigned, [
                    'assigned_to' => $old,
                ], ['assigned_to' => null]);
            }

            return $conversation;
        }

        $tenant = $this->tenants->required();

        $user = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->first();

        if (! $user instanceof User || ! $user->hasPermissionTo('conversations.view', $tenant)) {
            throw BusinessRuleViolation::because(
                'The selected team member cannot access this inbox.',
                ['user_id' => $userId],
                'invalid_assignee',
            );
        }

        $old = $conversation->assigned_to;
        $conversation->forceFill(['assigned_to' => $user->getKey()])->save();

        if ($old !== $user->getKey()) {
            $this->auditConversation($conversation, AuditAction::ConversationAssigned, [
                'assigned_to' => $old,
            ], ['assigned_to' => $user->getKey()]);

            $this->notifyAssignment($conversation, $user, (int) $tenant->getKey());
        }

        return $conversation;
    }

    /**
     * Team members that may be assigned a thread, without exposing the full team
     * management endpoint to staff who only work the inbox.
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(): Collection
    {
        $tenant = $this->tenants->required();

        $users = User::query()
            ->where('status', 'active')
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
            ->orderBy('name')
            ->get();

        return $users
            ->filter(fn (User $user): bool => $user->hasPermissionTo('conversations.view', $tenant))
            ->values();
    }

    public function changeStatus(Conversation $conversation, ConversationStatus $status): Conversation
    {
        $old = $conversation->status;

        if ($old === $status) {
            return $conversation;
        }

        $conversation->forceFill([
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
            'unread_count' => $status->isClosed() ? 0 : $conversation->unread_count,
        ])->save();

        $this->auditConversation($conversation, AuditAction::ConversationStatusChanged, [
            'status' => $old->value,
        ], ['status' => $status->value]);

        return $conversation;
    }

    /**
     * Mark the thread read for the current user. This is deliberately explicit:
     * opening a conversation should not silently erase the unread state another
     * agent depends on.
     */
    public function markRead(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'unread_count' => 0,
            'last_read_at' => now(),
        ])->save();

        return $conversation;
    }

    /**
     * Append a message to a thread.
     *
     * `external_id` is the provider's id. `idempotency_key` is the caller's own
     * stable key, which is what makes a retried send safe. Either can be absent,
     * but a webhook message always carries the provider id.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addMessage(Conversation $conversation, MessageDirection $direction, array $attributes): ConversationMessage
    {
        $type = $attributes['type'] ?? MessageType::Text;
        $type = $type instanceof MessageType ? $type : MessageType::from((string) $type);
        $isInternal = $type->isInternal() || (bool) ($attributes['is_internal'] ?? false);
        $sentAt = $attributes['sent_at'] ?? now();

        $message = DB::transaction(function () use ($conversation, $direction, $attributes, $type, $isInternal, $sentAt): ConversationMessage {
            $values = [
                'user_id' => $attributes['user_id'] ?? null,
                'type' => $type,
                'body' => $attributes['body'] ?? null,
                'meta' => $attributes['meta'] ?? null,
                'delivery_status' => array_key_exists('delivery_status', $attributes)
                    ? $attributes['delivery_status']
                    : ($direction === MessageDirection::Outbound ? 'sent' : 'received'),
                'sent_at' => $sentAt,
                'is_internal' => $isInternal,
                'idempotency_key' => $attributes['idempotency_key'] ?? null,
                'failure_code' => $attributes['failure_code'] ?? null,
                'failure_reason' => $attributes['failure_reason'] ?? null,
                'failed_at' => $attributes['failed_at'] ?? null,
                'retry_of_id' => $attributes['retry_of_id'] ?? null,
                'attempt_count' => $attributes['attempt_count'] ?? 0,
            ];

            $externalId = $attributes['external_id'] ?? null;
            $idempotencyKey = $values['idempotency_key'];

            if ($externalId !== null) {
                $message = ConversationMessage::query()->updateOrCreate(
                    [
                        'conversation_id' => $conversation->getKey(),
                        'direction' => $direction,
                        'external_id' => $externalId,
                    ],
                    $values,
                );
            } elseif (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $message = ConversationMessage::query()->updateOrCreate(
                    [
                        'conversation_id' => $conversation->getKey(),
                        'idempotency_key' => $idempotencyKey,
                    ],
                    $values + [
                        'direction' => $direction,
                        'external_id' => null,
                    ],
                );
            } else {
                $message = ConversationMessage::query()->create([
                    'conversation_id' => $conversation->getKey(),
                    'direction' => $direction,
                    'external_id' => null,
                ] + $values);
            }

            $this->touch($conversation, $message, $direction, $sentAt, $isInternal);

            return $message;
        });

        if ($direction === MessageDirection::Inbound && $message->wasRecentlyCreated && ! $isInternal) {
            $this->notifyInboundMessage($conversation, $message);
        }

        if ($direction === MessageDirection::Outbound && $message->wasRecentlyCreated) {
            $this->auditMessage($conversation, $message, $isInternal ? AuditAction::ConversationNoteAdded : AuditAction::ConversationMessageSent);
        }

        return $message;
    }

    /**
     * Update the denormalised fields the inbox uses to render a row without
     * touching its entire history.
     */
    private function touch(
        Conversation $conversation,
        ConversationMessage $message,
        MessageDirection $direction,
        mixed $sentAt,
        bool $isInternal,
    ): void {
        $conversation->forceFill(['last_message_id' => $message->getKey()]);

        if ($conversation->last_message_at === null || $sentAt >= $conversation->last_message_at) {
            $conversation->last_message_at = $sentAt;
        }

        if ($direction === MessageDirection::Inbound && ! $isInternal) {
            if ($conversation->last_inbound_at === null || $sentAt >= $conversation->last_inbound_at) {
                $conversation->last_inbound_at = $sentAt;
            }

            if ($message->wasRecentlyCreated) {
                $conversation->unread_count = (int) $conversation->unread_count + 1;
            }
        }

        $conversation->save();
    }

    /**
     * Whether a free-form reply is allowed right now.
     *
     * @return array{is_open: bool, expires_at: string|null, requires_template: bool}
     */
    public function replyWindow(Conversation $conversation): array
    {
        return $conversation->replyWindow();
    }

    /**
     * Refuse a free-form reply outside the provider's messaging window. The
     * caller must send an approved template instead; pretending a text message
     * went out would be a provider-side failure hidden from the agent.
     */
    public function assertTextReplyAllowed(Conversation $conversation): void
    {
        if ($conversation->channel !== 'whatsapp') {
            return;
        }

        if (! $conversation->replyWindow()['is_open']) {
            throw BusinessRuleViolation::because(
                'WhatsApp only allows free-form replies within 24 hours of the customer\'s last message. Send an approved template to reopen the conversation.',
                ['conversation_id' => $conversation->getKey()],
                'whatsapp_messaging_window_closed',
            );
        }
    }

    private function notifyInboundMessage(Conversation $conversation, ConversationMessage $message): void
    {
        $conversation->loadMissing('customer');
        $customer = $conversation->customer;
        $name = $customer?->name ?: 'A customer';

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_CONVERSATION_MESSAGE_RECEIVED,
            tenantId: (int) $conversation->tenant_id,
            dedupeKey: 'tenant.conversation.message_received:'.$message->getKey(),
            data: [
                'customer' => $name,
                'preview' => $this->preview($message->body),
            ],
            actionUrl: '/conversations',
        ));

        event(AutomationConversationMessageReceived::fromMessage($conversation, $message));
    }

    /**
     * Tell the person a thread was handed to, and only that person. Assignment
     * is a directed action, so the audience is named explicitly rather than
     * derived from everyone who happens to hold inbox access.
     */
    private function notifyAssignment(Conversation $conversation, User $assignee, int $tenantId): void
    {
        $conversation->loadMissing('customer');
        $customer = $conversation->customer?->name ?: 'A customer';

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_CONVERSATION_ASSIGNED,
            tenantId: $tenantId,
            dedupeKey: 'tenant.conversation.assigned:'.$conversation->getKey().':'.$assignee->getKey(),
            data: [
                'customer' => $customer,
                'preview' => $conversation->subject ?: 'A conversation needs your attention.',
            ],
            actionUrl: '/conversations',
            actorId: auth()->check() ? (int) auth()->id() : null,
            recipientIds: [(int) $assignee->getKey()],
        ));
    }

    private function preview(?string $body): string
    {
        $body = trim((string) $body);

        if ($body === '') {
            return 'New message';
        }

        return mb_strlen($body) > 120 ? mb_substr($body, 0, 117).'…' : $body;
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
    public function markDelivery(
        array $externalIds,
        string $state,
        ?string $failureReason = null,
        ?string $failureCode = null,
    ): int {
        if ($externalIds === []) {
            return 0;
        }

        $updates = match ($state) {
            'delivered' => [
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ],
            'read' => [
                'delivery_status' => 'read',
                'delivered_at' => now(),
                'read_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
                'failed_at' => null,
            ],
            'failed' => [
                'delivery_status' => 'failed',
                'failure_code' => $failureCode,
                'failure_reason' => $failureReason,
                'failed_at' => now(),
            ],
            default => [],
        };

        if ($updates === []) {
            return 0;
        }

        $query = ConversationMessage::query()
            ->whereIn('external_id', $externalIds)
            ->where('direction', MessageDirection::Outbound);

        // Receipts are not delivered in order, so each state only moves forward.
        $confirmed = ['delivered', 'read'];

        $query->where(function ($inner) use ($confirmed, $state): void {
            $inner->whereNull('delivery_status')
                ->when(
                    $state === 'read',
                    fn ($unconfirmed) => $unconfirmed->orWhere('delivery_status', '!=', 'read'),
                    fn ($unconfirmed) => $unconfirmed->orWhereNotIn('delivery_status', $confirmed),
                );
        });

        if ($state !== 'failed') {
            return $query->update($updates);
        }

        // A failure is written per row: the reason is merged into the existing
        // payload (a bulk update cannot merge JSON) and each affected message
        // is announced to the agent who sent it, after the row is saved.
        $updated = 0;
        $failed = [];

        foreach ($query->with('conversation.customer')->get() as $message) {
            $message->forceFill($updates + [
                'meta' => array_merge($message->meta ?? [], ['error' => $failureReason ?? 'unknown']),
            ])->save();

            $failed[] = $message;
            $updated++;
        }

        foreach ($failed as $message) {
            $this->notifyDeliveryFailure($message, $failureReason);
        }

        return $updated;
    }

    /**
     * Announce a failed outbound message to the person who sent it. A failure is
     * worth telling one agent about, not the whole inbox, so the author is named
     * when the message records one.
     */
    private function notifyDeliveryFailure(ConversationMessage $message, ?string $failureReason): void
    {
        $conversation = $message->conversation;

        if (! $conversation instanceof Conversation) {
            return;
        }

        $customer = $conversation->customer?->name ?: 'A customer';
        $reason = trim((string) ($failureReason ?? 'Unknown delivery error'));

        if (mb_strlen($reason) > 160) {
            $reason = mb_substr($reason, 0, 157).'...';
        }

        $senderId = $message->user_id !== null ? (int) $message->user_id : null;

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_MESSAGE_FAILED,
            tenantId: (int) $conversation->tenant_id,
            dedupeKey: 'tenant.message.failed:'.$message->getKey(),
            data: [
                'customer' => $customer,
                'reason' => $reason,
                'preview' => $this->preview($message->body),
            ],
            actionUrl: '/conversations',
            recipientIds: $senderId !== null ? [$senderId] : null,
        ));
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
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function auditConversation(Conversation $conversation, AuditAction $action, array $old, array $new): void
    {
        AuditLog::write($action, $conversation, $old, $new);
    }

    private function auditMessage(Conversation $conversation, ConversationMessage $message, AuditAction $action): void
    {
        AuditLog::write($action, $conversation, null, [
            'message_id' => $message->getKey(),
            'direction' => $message->direction->value,
            'type' => $message->type->value,
        ]);
    }
}
