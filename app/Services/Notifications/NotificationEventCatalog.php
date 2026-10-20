<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationScope;
use App\Enums\NotificationSeverity;
use InvalidArgumentException;

/**
 * The catalogue of every notification the platform can raise.
 *
 * This is the single source of truth for a notification's audience, preference
 * switch, channels and localized copy. Emitters name an event by its constant
 * key and hand over data; everything else — who receives it, whether their
 * preference allows it, which language it renders in — is decided here and in
 * the dispatcher, never at the call site.
 *
 * A key that is not in this catalogue refuses to dispatch rather than mailing
 * something untemplated, so a typo in an emitter is caught in development.
 */
final class NotificationEventCatalog
{
    public const string PLATFORM_WAITLIST_RECEIVED = 'platform.waitlist.received';

    public const string PLATFORM_CONTACT_RECEIVED = 'platform.contact.received';

    public const string PLATFORM_TENANT_ONBOARDED = 'platform.tenant.onboarded';

    public const string TENANT_ORDER_CREATED = 'tenant.order.created';

    public const string TENANT_ORDER_STATUS_CHANGED = 'tenant.order.status_changed';

    public const string TENANT_INVENTORY_LOW_STOCK = 'tenant.inventory.low_stock';

    public const string TENANT_CONVERSATION_MESSAGE_RECEIVED = 'tenant.conversation.message_received';

    /**
     * Low-stock alerts repeat, so they are rate-limited per inventory row rather
     * than fired once: a busy day should not turn a dip below threshold into one
     * email per order.
     */
    public const int LOW_STOCK_COOLDOWN_SECONDS = 86400;

    /**
     * @var array<string, NotificationEvent>
     */
    private array $events;

    public function __construct()
    {
        $this->events = $this->define();
    }

    public function event(string $key): NotificationEvent
    {
        if (! isset($this->events[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown notification event [%s].', $key));
        }

        return $this->events[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->events[$key]);
    }

    /**
     * @return array<string, NotificationEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, NotificationEvent>
     */
    private function define(): array
    {
        $events = [
            new NotificationEvent(
                key: self::PLATFORM_WAITLIST_RECEIVED,
                scope: NotificationScope::Platform,
                category: 'leads',
                severity: NotificationSeverity::Info,
                messages: [
                    'en' => ['title' => 'New waitlist request', 'body' => '{name} joined the waitlist.'],
                    'ar' => ['title' => 'طلب قائمة انتظار جديد', 'body' => 'انضم {name} إلى قائمة الانتظار.'],
                ],
            ),
            new NotificationEvent(
                key: self::PLATFORM_CONTACT_RECEIVED,
                scope: NotificationScope::Platform,
                category: 'leads',
                severity: NotificationSeverity::Info,
                messages: [
                    'en' => ['title' => 'New contact message', 'body' => '{name} sent a message: {subject}'],
                    'ar' => ['title' => 'رسالة تواصل جديدة', 'body' => 'أرسل {name} رسالة: {subject}'],
                ],
            ),
            new NotificationEvent(
                key: self::PLATFORM_TENANT_ONBOARDED,
                scope: NotificationScope::Platform,
                category: 'tenants',
                severity: NotificationSeverity::Success,
                messages: [
                    'en' => ['title' => 'New workspace created', 'body' => '{name} created the workspace "{tenant}".'],
                    'ar' => ['title' => 'تم إنشاء مساحة عمل جديدة', 'body' => 'أنشأ {name} مساحة العمل "{tenant}".'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_CREATED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'New order {order_number}', 'body' => '{customer} placed an order worth {total}.'],
                    'ar' => ['title' => 'طلب جديد {order_number}', 'body' => 'أنشأ {customer} طلبًا بقيمة {total}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_STATUS_CHANGED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Order {order_number} is now {status}', 'body' => 'Order {order_number} moved from {from} to {to}.'],
                    'ar' => ['title' => 'أصبح الطلب {order_number} الآن {status}', 'body' => 'انتقل الطلب {order_number} من {from} إلى {to}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_INVENTORY_LOW_STOCK,
                scope: NotificationScope::Tenant,
                category: 'inventory',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'inventory.manage',
                preference: 'low_stock',
                cooldownSeconds: self::LOW_STOCK_COOLDOWN_SECONDS,
                messages: [
                    'en' => ['title' => 'Low stock: {product}', 'body' => '{product} has only {quantity} left at {store}.'],
                    'ar' => ['title' => 'مخزون منخفض: {product}', 'body' => 'تبقّى {quantity} فقط من {product} في {store}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_CONVERSATION_MESSAGE_RECEIVED,
                scope: NotificationScope::Tenant,
                category: 'messages',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'conversations.view',
                preference: 'new_messages',
                messages: [
                    'en' => ['title' => 'New message from {customer}', 'body' => '{preview}'],
                    'ar' => ['title' => 'رسالة جديدة من {customer}', 'body' => '{preview}'],
                ],
            ),
        ];

        return $this->keyBy($events);
    }

    /**
     * @param  array<string, NotificationEvent>  $events
     */
    private function keyBy(array $events): array
    {
        $keyed = [];

        foreach ($events as $event) {
            $keyed[$event->key] = $event;
        }

        return $keyed;
    }
}
