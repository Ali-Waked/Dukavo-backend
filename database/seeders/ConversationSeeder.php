<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * WhatsApp threads that read like a real support inbox.
 *
 * Each conversation is a ready-written script in the merchant's own language,
 * played out between a real customer and the shop's staff. The freshest
 * threads sit open at the top of the inbox, older ones are closed with a
 * timestamp, and every message carries the delivery state a provider webhook
 * would have reported — so "sent", "delivered" and "read" all differ.
 */
class ConversationSeeder extends Seeder
{
    /** Share of threads still awaiting a reply at the top of the inbox. */
    private const OPEN_SHARE = 0.3;

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run($tenant, function () use ($tenant, $definition): void {
                $this->seedConversations($tenant, $definition);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedConversations(Tenant $tenant, array $definition): void
    {
        $count = (int) $definition['conversation_count'];
        $account = WhatsappAccount::query()->first();
        $members = $tenant->users()->get();

        if ($count === 0 || $account === null || $members->isEmpty()) {
            return;
        }

        $owner = $members->first(fn (User $user): bool => (bool) $user->pivot->is_owner) ?? $members->first();
        $locale = (string) $definition['locale'];
        $scripts = DemoPeople::messageScripts($locale);
        $openCount = (int) ceil($count * self::OPEN_SHARE);
        $slug = (string) $definition['slug'];

        foreach ($this->drawCustomers($count) as $position => $customer) {
            $script = $scripts[$position % count($scripts)];
            $status = $position < $openCount
                ? ConversationStatus::Open
                : ($position === $openCount && $count >= 6 ? ConversationStatus::Pending : ConversationStatus::Closed);

            $lastMessageAt = $this->lastMessageAt($status);
            $messages = $this->scriptMessages($script['messages'], $lastMessageAt);
            $closedAt = $status === ConversationStatus::Closed
                ? $lastMessageAt->copy()->addHours(mt_rand(2, 48))->toDateTimeString()
                : null;

            $conversation = Conversation::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'store_id' => null,
                'customer_id' => $customer->getKey(),
                'whatsapp_account_id' => $account->getKey(),
                'assigned_to' => mt_rand(0, 99) < 55 ? $members[mt_rand(0, $members->count() - 1)]->getKey() : null,
                'channel' => 'whatsapp',
                'channel_account' => $account->phone_number_id,
                'external_id' => 'wamid.DEMO-'.$slug.'-'.$position,
                'display_name' => $customer->name,
                'phone' => $customer->phone,
                'subject' => $this->subject($script['subject'] ?? null, $customer, $locale),
                'status' => $status,
                'closed_at' => $closedAt,
                'last_message_at' => $lastMessageAt,
                'created_at' => $messages[0]['sent_at'],
                'updated_at' => $closedAt ?? $lastMessageAt,
            ]);

            $this->writeMessages($conversation, $messages, $members, $owner, $slug.'-'.$position);
        }
    }

    /**
     * Distinct customers for the threads, drawn with Fisher–Yates on the
     * seeded engine so no customer answers themselves twice.
     *
     * @return list<Customer>
     */
    private function drawCustomers(int $count): array
    {
        $customers = Customer::query()->get()->values()->all();
        $indexes = array_keys($customers);

        for ($i = count($indexes) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$indexes[$i], $indexes[$j]] = [$indexes[$j], $indexes[$i]];
        }

        $picks = [];

        foreach (array_slice($indexes, 0, min($count, count($indexes))) as $index) {
            $picks[] = $customers[$index];
        }

        return $picks;
    }

    /**
     * Open threads are minutes old, closed ones weeks old: the inbox's first
     * screen always shows something waiting.
     */
    private function lastMessageAt(ConversationStatus $status): Carbon
    {
        return match ($status) {
            ConversationStatus::Open => now()->subMinutes(mt_rand(8, 2 * 24 * 60)),
            ConversationStatus::Pending => now()->subMinutes(mt_rand(60, 3 * 24 * 60)),
            ConversationStatus::Closed => now()->subDays(mt_rand(3, 40)),
        };
    }

    /**
     * Walk the script backwards from the last message, giving each exchange a
     * gap of a few minutes — the rhythm of a chat, not a transcript dump.
     *
     * @param  list<array{direction: string, body: string}>  $script
     * @return list<array{direction: MessageDirection, body: string, sent_at: Carbon}>
     */
    private function scriptMessages(array $script, Carbon $lastMessageAt): array
    {
        $times = [$lastMessageAt->copy()];

        for ($i = 1; $i < count($script); $i++) {
            $times[] = $times[count($times) - 1]->copy()->subMinutes(mt_rand(3, 15));
        }

        $times = array_reverse($times);
        $messages = [];

        foreach ($script as $index => $message) {
            $messages[] = [
                'direction' => $message['direction'] === 'in' ? MessageDirection::Inbound : MessageDirection::Outbound,
                'body' => $message['body'],
                'sent_at' => $times[$index],
            ];
        }

        return $messages;
    }

    /**
     * @param  list<array{direction: MessageDirection, body: string, sent_at: Carbon}>  $script
     * @param  Collection<int, User>  $members
     */
    private function writeMessages(
        Conversation $conversation,
        array $script,
        Collection $members,
        User $owner,
        string $thread,
    ): void {
        foreach ($script as $index => $message) {
            $outbound = $message['direction'] === MessageDirection::Outbound;
            $deliveredAt = $outbound ? $message['sent_at']->copy()->addSeconds(mt_rand(15, 90)) : null;
            $receipt = $outbound ? mt_rand(0, 99) : 100;

            $deliveryStatus = match (true) {
                ! $outbound => 'received',
                $receipt < 70 => 'read',
                $receipt < 95 => 'delivered',
                default => 'sent',
            };

            ConversationMessage::forceCreate([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->getKey(),
                'user_id' => $outbound ? $this->author($conversation, $members, $owner)->getKey() : null,
                'direction' => $message['direction'],
                'type' => MessageType::Text,
                'body' => $message['body'],
                'external_id' => 'wamid.DEMO-'.$thread.'-'.$index,
                'delivery_status' => $deliveryStatus,
                'delivered_at' => $deliveredAt !== null && $deliveryStatus !== 'sent' ? $deliveredAt : null,
                'read_at' => $deliveryStatus === 'read' ? $deliveredAt->copy()->addSeconds(mt_rand(30, 600)) : null,
                'sent_at' => $message['sent_at'],
                'created_at' => $message['sent_at'],
                'updated_at' => $message['sent_at'],
            ]);
        }
    }

    /**
     * The staff member on the other end: the assignee when the thread has
     * one, the owner when it does not.
     *
     * @param  Collection<int, User>  $members
     */
    private function author(Conversation $conversation, Collection $members, User $owner): User
    {
        $assigned = $members->first(
            fn (User $user): bool => $conversation->assigned_to !== null
                && $user->getKey() === $conversation->assigned_to,
        );

        return $assigned ?? $owner;
    }

    /**
     * Threads about an order name the real order number it was about.
     */
    private function subject(?string $subject, Customer $customer, string $locale): ?string
    {
        if ($subject === null || ! str_contains($subject, '{order}')) {
            return $subject;
        }

        $orderNumbers = Order::query()
            ->where('customer_id', $customer->getKey())
            ->pluck('order_number');

        if ($orderNumbers->isEmpty()) {
            $orderNumbers = Order::query()->pluck('order_number');
        }

        if ($orderNumbers->isEmpty()) {
            return $locale === 'ar' ? 'طلب' : 'Order';
        }

        return str_replace('{order}', (string) $orderNumbers[mt_rand(0, $orderNumbers->count() - 1)], $subject);
    }
}
