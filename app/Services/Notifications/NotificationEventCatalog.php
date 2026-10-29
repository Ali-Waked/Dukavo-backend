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

    public const string PLATFORM_TENANT_SUSPENDED = 'platform.tenant.suspended';

    public const string PLATFORM_TENANT_REACTIVATED = 'platform.tenant.reactivated';

    public const string PLATFORM_SUBSCRIPTION_EVENT = 'platform.subscription.event';

    public const string TENANT_ORDER_CREATED = 'tenant.order.created';

    public const string TENANT_ORDER_STATUS_CHANGED = 'tenant.order.status_changed';

    public const string TENANT_INVENTORY_LOW_STOCK = 'tenant.inventory.low_stock';

    public const string TENANT_CONVERSATION_MESSAGE_RECEIVED = 'tenant.conversation.message_received';

    public const string TENANT_CONVERSATION_ASSIGNED = 'tenant.conversation.assigned';

    public const string TENANT_MESSAGE_FAILED = 'tenant.message.failed';

    public const string TENANT_APPROVAL_REQUEST = 'tenant.approval.request';

    public const string TENANT_AI_ACTION_AWAITING_REVIEW = 'tenant.ai_action.awaiting_review';

    public const string TENANT_AUTOMATION_TRIGGERED = 'tenant.automation.triggered';

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
            new NotificationEvent(
                key: self::TENANT_CONVERSATION_ASSIGNED,
                scope: NotificationScope::Tenant,
                category: 'messages',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'conversations.view',
                preference: 'conversation_assignments',
                messages: [
                    'en' => ['title' => 'Conversation assigned to you', 'body' => '{customer} — {preview}'],
                    'ar' => ['title' => 'تم إسناد محادثة إليك', 'body' => '{customer} — {preview}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_MESSAGE_FAILED,
                scope: NotificationScope::Tenant,
                category: 'messages',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'conversations.view',
                preference: 'delivery_failures',
                messages: [
                    'en' => ['title' => 'Message to {customer} failed', 'body' => 'We could not deliver your message: {reason}'],
                    'ar' => ['title' => 'فشل إرسال رسالة إلى {customer}', 'body' => 'لم نتمكن من إرسال رسالتك: {reason}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_AI_ACTION_AWAITING_REVIEW,
                scope: NotificationScope::Tenant,
                category: 'automation',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'ai.approve',
                preference: 'approvals',
                messages: [
                    'en' => ['title' => 'AI action needs your approval', 'body' => '{summary}'],
                    'ar' => ['title' => 'إجراء ذكاء اصطناعي يحتاج موافقتك', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::PLATFORM_TENANT_SUSPENDED,
                scope: NotificationScope::Platform,
                category: 'tenants',
                severity: NotificationSeverity::Warning,
                messages: [
                    'en' => ['title' => 'Workspace suspended', 'body' => '{tenant} was suspended.'],
                    'ar' => ['title' => 'تم تعليق مساحة العمل', 'body' => 'تم تعليق {tenant}.'],
                ],
            ),
            new NotificationEvent(
                key: self::PLATFORM_TENANT_REACTIVATED,
                scope: NotificationScope::Platform,
                category: 'tenants',
                severity: NotificationSeverity::Success,
                messages: [
                    'en' => ['title' => 'Workspace reactivated', 'body' => '{tenant} is active again.'],
                    'ar' => ['title' => 'تمت إعادة تفعيل مساحة العمل', 'body' => 'أصبحت {tenant} نشطة مجددًا.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_APPROVAL_REQUEST,
                scope: NotificationScope::Tenant,
                category: 'approvals',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'ai.approve',
                preference: 'approvals',
                messages: [
                    'en' => ['title' => 'Approval required', 'body' => '{summary}'],
                    'ar' => ['title' => 'مطلوب موافقة', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_AUTOMATION_TRIGGERED,
                scope: NotificationScope::Tenant,
                category: 'automation',
                severity: NotificationSeverity::Info,
                channels: [],
                permission: 'automation.manage',
                preference: null,
                messages: [
                    'en' => ['title' => 'Automation: {rule}', 'body' => '{message}'],
                    'ar' => ['title' => 'أتمتة: {rule}', 'body' => '{message}'],
                ],
            ),
            new NotificationEvent(
                key: self::PLATFORM_SUBSCRIPTION_EVENT,
                scope: NotificationScope::Platform,
                category: 'billing',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                messages: [
                    'en' => ['title' => 'Subscription update', 'body' => '{summary}'],
                    'ar' => ['title' => 'تحديث الاشتراك', 'body' => '{summary}'],
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
