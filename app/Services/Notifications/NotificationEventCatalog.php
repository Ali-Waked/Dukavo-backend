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

    public const string TENANT_ORDER_CREATED = 'tenant.order.created';

    public const string TENANT_ORDER_PAID = 'tenant.order.paid';

    public const string TENANT_ORDER_SHIPPED = 'tenant.order.shipped';

    public const string TENANT_ORDER_DELIVERED = 'tenant.order.delivered';

    public const string TENANT_ORDER_CANCELLED = 'tenant.order.cancelled';

    public const string TENANT_ORDER_REFUNDED = 'tenant.order.refunded';

    public const string TENANT_ORDER_STATUS_CHANGED = 'tenant.order.status_changed';

    public const string TENANT_INVENTORY_LOW_STOCK = 'tenant.inventory.low_stock';

    public const string TENANT_INVENTORY_OUT_OF_STOCK = 'tenant.inventory.out_of_stock';

    public const string TENANT_INVENTORY_RESTOCK_REMINDER = 'tenant.inventory.restock_reminder';

    public const string TENANT_INVENTORY_ADJUSTED = 'tenant.inventory.adjusted';

    public const string TENANT_CONVERSATION_MESSAGE_RECEIVED = 'tenant.conversation.message_received';

    public const string TENANT_CONVERSATION_ASSIGNED = 'tenant.conversation.assigned';

    public const string TENANT_CONVERSATION_ATTENTION = 'tenant.conversation.attention';

    public const string TENANT_MESSAGE_FAILED = 'tenant.message.failed';

    public const string TENANT_APPROVAL_REQUEST = 'tenant.approval.request';

    public const string TENANT_AI_ACTION_AWAITING_REVIEW = 'tenant.ai_action.awaiting_review';

    public const string TENANT_AI_ACTION_APPROVED = 'tenant.ai_action.approved';

    public const string TENANT_AI_ACTION_REJECTED = 'tenant.ai_action.rejected';

    public const string TENANT_AI_ACTION_FAILED = 'tenant.ai_action.failed';

    public const string TENANT_REPORT_WEEKLY = 'tenant.report.weekly';

    public const string TENANT_REPORT_MONTHLY = 'tenant.report.monthly';

    public const string TENANT_REPORT_PERFORMANCE = 'tenant.report.performance';

    public const string TENANT_ANNOUNCEMENT_PRODUCT = 'tenant.announcement.product';

    public const string TENANT_ANNOUNCEMENT_MAINTENANCE = 'tenant.announcement.maintenance';

    public const string TENANT_ANNOUNCEMENT_RELEASE = 'tenant.announcement.release';

    public const string TENANT_AUTOMATION_TRIGGERED = 'tenant.automation.triggered';

    public const string TENANT_TEAM_MEMBER_INVITED = 'tenant.team.member_invited';

    public const string TENANT_TEAM_MEMBER_JOINED = 'tenant.team.member_joined';

    public const string TENANT_TEAM_ROLE_CHANGED = 'tenant.team.role_changed';

    public const string TENANT_TEAM_PERMISSION_CHANGED = 'tenant.team.permission_changed';

    public const string TENANT_TEAM_MEMBER_REMOVED = 'tenant.team.member_removed';

    public const string TENANT_SECURITY_PASSWORD_RESET = 'tenant.security.password_reset';

    /**
     * Restock reminders repeat while an item stays low, so they are limited to
     * one reminder per inventory row every seven days.
     */
    public const int RESTOCK_REMINDER_COOLDOWN_SECONDS = 604800;

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
                key: self::TENANT_ORDER_PAID,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Success,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Payment received for {order_number}', 'body' => 'We recorded {total} as paid for this order.'],
                    'ar' => ['title' => 'تم استلام دفعة للطلب {order_number}', 'body' => 'سجّلنا مبلغ {total} مدفوعًا لهذا الطلب.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_SHIPPED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Order {order_number} has shipped', 'body' => 'The order left the warehouse and stock was deducted.'],
                    'ar' => ['title' => 'تم شحن الطلب {order_number}', 'body' => 'غادر الطلب المستودع وتم خصم المخزون.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_DELIVERED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Success,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Order {order_number} was delivered', 'body' => 'The order is now marked as delivered.'],
                    'ar' => ['title' => 'تم تسليم الطلب {order_number}', 'body' => 'أصبح الطلب الآن مسلّمًا.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_CANCELLED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Order {order_number} was cancelled', 'body' => 'Any reserved or shipped stock has been released.'],
                    'ar' => ['title' => 'تم إلغاء الطلب {order_number}', 'body' => 'تم تحرير أي مخزون محجوز أو مشحون.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ORDER_REFUNDED,
                scope: NotificationScope::Tenant,
                category: 'orders',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'orders.view',
                preference: 'order_updates',
                messages: [
                    'en' => ['title' => 'Refund recorded for {order_number}', 'body' => '{total} is marked as refunded.'],
                    'ar' => ['title' => 'تم تسجيل استرداد للطلب {order_number}', 'body' => 'تم تعليم مبلغ {total} كمسترد.'],
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
                key: self::TENANT_INVENTORY_OUT_OF_STOCK,
                scope: NotificationScope::Tenant,
                category: 'inventory',
                severity: NotificationSeverity::Critical,
                channels: [NotificationChannel::Mail, NotificationChannel::WhatsApp],
                permission: 'inventory.manage',
                preference: 'low_stock',
                cooldownSeconds: self::LOW_STOCK_COOLDOWN_SECONDS,
                messages: [
                    'en' => ['title' => 'Out of stock: {product}', 'body' => '{product} has no sellable stock left at {store}.'],
                    'ar' => ['title' => 'نفد المخزون: {product}', 'body' => 'لم يتبقَّ مخزون قابل للبيع من {product} في {store}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_INVENTORY_RESTOCK_REMINDER,
                scope: NotificationScope::Tenant,
                category: 'inventory',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'inventory.manage',
                preference: 'low_stock',
                cooldownSeconds: self::RESTOCK_REMINDER_COOLDOWN_SECONDS,
                messages: [
                    'en' => ['title' => 'Restock reminder: {product}', 'body' => '{product} still has only {quantity} available at {store}.'],
                    'ar' => ['title' => 'تذكير بإعادة التخزين: {product}', 'body' => 'لا يزال المتاح من {product} {quantity} فقط في {store}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_INVENTORY_ADJUSTED,
                scope: NotificationScope::Tenant,
                category: 'inventory',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'inventory.manage',
                preference: 'low_stock',
                messages: [
                    'en' => ['title' => 'Stock adjusted: {product}', 'body' => '{product} changed from {previous_quantity} to {new_quantity} at {store}.'],
                    'ar' => ['title' => 'تم تعديل المخزون: {product}', 'body' => 'تغيّر {product} من {previous_quantity} إلى {new_quantity} في {store}.'],
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
                key: self::TENANT_CONVERSATION_ATTENTION,
                scope: NotificationScope::Tenant,
                category: 'messages',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'conversations.view',
                preference: 'new_messages',
                messages: [
                    'en' => ['title' => 'A conversation needs attention', 'body' => '{customer} — {preview}'],
                    'ar' => ['title' => 'محادثة تحتاج إلى انتباه', 'body' => '{customer} — {preview}'],
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
                key: self::TENANT_AI_ACTION_APPROVED,
                scope: NotificationScope::Tenant,
                category: 'automation',
                severity: NotificationSeverity::Success,
                channels: [NotificationChannel::Mail],
                permission: 'ai.approve',
                preference: 'approvals',
                messages: [
                    'en' => ['title' => 'AI action approved', 'body' => '{summary}'],
                    'ar' => ['title' => 'تمت الموافقة على إجراء ذكاء اصطناعي', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_AI_ACTION_REJECTED,
                scope: NotificationScope::Tenant,
                category: 'automation',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'ai.approve',
                preference: 'approvals',
                messages: [
                    'en' => ['title' => 'AI action rejected', 'body' => '{summary}'],
                    'ar' => ['title' => 'تم رفض إجراء ذكاء اصطناعي', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_AI_ACTION_FAILED,
                scope: NotificationScope::Tenant,
                category: 'automation',
                severity: NotificationSeverity::Critical,
                channels: [NotificationChannel::Mail],
                permission: 'ai.approve',
                preference: 'approvals',
                messages: [
                    'en' => ['title' => 'AI action failed', 'body' => '{summary}'],
                    'ar' => ['title' => 'فشل إجراء ذكاء اصطناعي', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_REPORT_WEEKLY,
                scope: NotificationScope::Tenant,
                category: 'reports',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'profit.view',
                preference: 'weekly_summary',
                messages: [
                    'en' => ['title' => 'Your weekly business summary', 'body' => '{period}: {orders} orders and {net_revenue} net revenue.'],
                    'ar' => ['title' => 'ملخص أعمالك الأسبوعي', 'body' => '{period}: {orders} طلبًا وصافي إيرادات {net_revenue}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_REPORT_MONTHLY,
                scope: NotificationScope::Tenant,
                category: 'reports',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'profit.view',
                preference: 'weekly_summary',
                messages: [
                    'en' => ['title' => 'Your monthly business report', 'body' => '{period}: {orders} orders and {net_revenue} net revenue.'],
                    'ar' => ['title' => 'تقرير أعمالك الشهري', 'body' => '{period}: {orders} طلبًا وصافي إيرادات {net_revenue}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_REPORT_PERFORMANCE,
                scope: NotificationScope::Tenant,
                category: 'reports',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'profit.view',
                preference: 'weekly_summary',
                messages: [
                    'en' => ['title' => 'Business performance summary', 'body' => '{period}: {orders} orders and {net_revenue} net revenue.'],
                    'ar' => ['title' => 'ملخص أداء الأعمال', 'body' => '{period}: {orders} طلبًا وصافي إيرادات {net_revenue}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ANNOUNCEMENT_PRODUCT,
                scope: NotificationScope::Tenant,
                category: 'announcements',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'dashboard.view',
                preference: 'product_news',
                messages: [
                    'en' => ['title' => '{title}', 'body' => '{summary}'],
                    'ar' => ['title' => '{title}', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ANNOUNCEMENT_RELEASE,
                scope: NotificationScope::Tenant,
                category: 'announcements',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'dashboard.view',
                preference: 'product_news',
                messages: [
                    'en' => ['title' => '{title}', 'body' => '{summary}'],
                    'ar' => ['title' => '{title}', 'body' => '{summary}'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_ANNOUNCEMENT_MAINTENANCE,
                scope: NotificationScope::Tenant,
                category: 'announcements',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'dashboard.view',
                preference: null,
                messages: [
                    'en' => ['title' => 'Scheduled maintenance', 'body' => '{summary}'],
                    'ar' => ['title' => 'صيانة مجدولة', 'body' => '{summary}'],
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
                key: self::TENANT_TEAM_MEMBER_INVITED,
                scope: NotificationScope::Tenant,
                category: 'team',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'users.manage',
                preference: 'team_activity',
                messages: [
                    'en' => ['title' => 'Team member invited', 'body' => '{actor} invited {member} as {role}.'],
                    'ar' => ['title' => 'تمت دعوة عضو إلى الفريق', 'body' => 'دعا {actor} {member} بصفة {role}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_TEAM_MEMBER_JOINED,
                scope: NotificationScope::Tenant,
                category: 'team',
                severity: NotificationSeverity::Success,
                channels: [NotificationChannel::Mail],
                permission: 'users.manage',
                preference: 'team_activity',
                messages: [
                    'en' => ['title' => 'Team member joined', 'body' => '{member} joined the workspace.'],
                    'ar' => ['title' => 'انضم عضو إلى الفريق', 'body' => 'انضم {member} إلى مساحة العمل.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_TEAM_ROLE_CHANGED,
                scope: NotificationScope::Tenant,
                category: 'team',
                severity: NotificationSeverity::Info,
                channels: [NotificationChannel::Mail],
                permission: 'users.manage',
                preference: 'team_activity',
                messages: [
                    'en' => ['title' => 'Team role changed', 'body' => '{member} is now {role}.'],
                    'ar' => ['title' => 'تغيّر دور عضو في الفريق', 'body' => 'أصبح دور {member} الآن {role}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_TEAM_PERMISSION_CHANGED,
                scope: NotificationScope::Tenant,
                category: 'team',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'roles.manage',
                preference: 'team_activity',
                messages: [
                    'en' => ['title' => 'Role permissions updated', 'body' => 'The permissions of {role} were updated by {actor}.'],
                    'ar' => ['title' => 'تم تحديث صلاحيات الدور', 'body' => 'حدّث {actor} صلاحيات {role}.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_TEAM_MEMBER_REMOVED,
                scope: NotificationScope::Tenant,
                category: 'team',
                severity: NotificationSeverity::Warning,
                channels: [NotificationChannel::Mail],
                permission: 'users.manage',
                preference: 'team_activity',
                messages: [
                    'en' => ['title' => 'Team member removed', 'body' => '{member} was removed from the workspace.'],
                    'ar' => ['title' => 'تمت إزالة عضو من الفريق', 'body' => 'أُزيل {member} من مساحة العمل.'],
                ],
            ),
            new NotificationEvent(
                key: self::TENANT_SECURITY_PASSWORD_RESET,
                scope: NotificationScope::Tenant,
                category: 'security',
                severity: NotificationSeverity::Critical,
                channels: [NotificationChannel::Mail],
                permission: 'dashboard.view',
                preference: 'security_alerts',
                messages: [
                    'en' => ['title' => 'Your password was reset', 'body' => 'If you did not make this change, contact support immediately.'],
                    'ar' => ['title' => 'تمت إعادة تعيين كلمة المرور', 'body' => 'إذا لم تقم بهذا التغيير، فتواصل مع الدعم فورًا.'],
                ],
            ),
        ];

        // Push is available for every event; individual preference switches
        // still decide whether a given person receives it.
        $events = array_map(
            static fn (NotificationEvent $event): NotificationEvent => $event->withChannel(NotificationChannel::Push),
            $events,
        );

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
