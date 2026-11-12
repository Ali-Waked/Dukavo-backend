<?php

declare(strict_types=1);

namespace App\Services\Configuration;

/**
 * The closed catalogue of platform availability flags.
 *
 * A flag answers one question: may this capability be used anywhere on the
 * platform? It is deliberately *not* a subscription entitlement. Dukavo has no
 * plan-based entitlement engine, so a flag never implies that a merchant paid
 * for a feature; it only lets an operator withdraw a capability that is not
 * ready, is being rolled out, or must be paused during an incident.
 *
 * Every key here is also a `features.*` setting in
 * {@see ConfigurationRegistry}, which is what makes the switch auditable and
 * visible in the dashboard. Runtime code must ask
 * {@see FeatureFlagService} rather than reading the value directly.
 */
final class FeatureCatalogue
{
    /**
     * @return list<array{key: string, label: string, labelAr: string, description: string, descriptionAr: string, default: bool, group: string}>
     */
    public static function flags(): array
    {
        return [
            [
                'key' => 'ai_assistant',
                'label' => 'AI assistant',
                'labelAr' => 'المساعد الذكي',
                'description' => 'AI reply drafting, insights and copilots. Provider keys and routing are managed in AI administration, not here.',
                'descriptionAr' => 'صياغة الردود والرؤى والمساعدين بالذكاء الاصطناعي. تُدار مفاتيح المزودين والتوجيه في إدارة الذكاء الاصطناعي وليس هنا.',
                'default' => true,
                'group' => 'intelligence',
            ],
            [
                'key' => 'ai_translation',
                'label' => 'AI translation',
                'labelAr' => 'الترجمة بالذكاء الاصطناعي',
                'description' => 'Generate draft translations for merchant and platform content. Approval still happens in the translation workspace.',
                'descriptionAr' => 'إنشاء مسودات ترجمة لمحتوى المنصة والتجار. تبقى الموافقة في مساحة عمل الترجمة.',
                'default' => true,
                'group' => 'intelligence',
            ],
            [
                'key' => 'whatsapp',
                'label' => 'WhatsApp channel',
                'labelAr' => 'قناة واتساب',
                'description' => 'Merchant WhatsApp connections, inbound conversations and templates. Platform credentials live in the WhatsApp settings page.',
                'descriptionAr' => 'اتصالات واتساب للتجار والمحادثات الواردة والقوالب. بيانات اعتماد المنصة في صفحة إعدادات واتساب.',
                'default' => true,
                'group' => 'channels',
            ],
            [
                'key' => 'omnichannel_integrations',
                'label' => 'Omnichannel integrations',
                'labelAr' => 'تكاملات القنوات المتعددة',
                'description' => 'Instagram, Messenger, Telegram, SMS, email and X connectors alongside WhatsApp.',
                'descriptionAr' => 'موصلات إنستغرام وماسنجر وتيليجرام والرسائل النصية والبريد وإكس إلى جانب واتساب.',
                'default' => true,
                'group' => 'channels',
            ],
            [
                'key' => 'email_notifications',
                'label' => 'Email notifications',
                'labelAr' => 'إشعارات البريد',
                'description' => 'Transactional and campaign email. Turning this off stops delivery but keeps templates and preferences intact.',
                'descriptionAr' => 'بريد المعاملات والحملات. إيقافه يوقف الإرسال ويحفظ القوالب والتفضيلات.',
                'default' => true,
                'group' => 'communication',
            ],
            [
                'key' => 'push_notifications',
                'label' => 'Push notifications',
                'labelAr' => 'الإشعارات الفورية',
                'description' => 'Mobile push delivery through the configured Firebase project.',
                'descriptionAr' => 'تسليم الإشعارات الفورية للجوال عبر مشروع Firebase المضبوط.',
                'default' => true,
                'group' => 'communication',
            ],
            [
                'key' => 'automation',
                'label' => 'Automation',
                'labelAr' => 'الأتمتة',
                'description' => 'Merchant automation rules and platform template installations.',
                'descriptionAr' => 'قواعد الأتمتة للتجار وتثبيت قوالب المنصة.',
                'default' => true,
                'group' => 'operations',
            ],
            [
                'key' => 'recovery',
                'label' => 'Lost-sale recovery',
                'labelAr' => 'استعادة المبيعات المفقودة',
                'description' => 'Recovery opportunity detection, follow-ups and recovered-revenue reporting.',
                'descriptionAr' => 'كشف فرص الاستعادة والمتابعة وتقارير الإيرادات المستعادة.',
                'default' => true,
                'group' => 'operations',
            ],
            [
                'key' => 'public_storefront',
                'label' => 'Public storefront',
                'labelAr' => 'المتجر العام',
                'description' => 'Public store pages, product browsing and the storefront cart.',
                'descriptionAr' => 'صفحات المتجر العامة وتصفح المنتجات وسلة المتجر.',
                'default' => true,
                'group' => 'commerce',
            ],
            [
                'key' => 'guest_checkout',
                'label' => 'Guest checkout',
                'labelAr' => 'الدفع كزائر',
                'description' => 'Allow a storefront order without an account. Stores may still require an account in their own checkout settings.',
                'descriptionAr' => 'السماح بطلب من المتجر دون حساب. يمكن للمتاجر إلزام الحساب في إعدادات الدفع الخاصة بها.',
                'default' => true,
                'group' => 'commerce',
            ],
            [
                'key' => 'online_payments',
                'label' => 'Online payments',
                'labelAr' => 'المدفوعات الإلكترونية',
                'description' => 'Hosted payment starts on the storefront. Provider availability is configured in the payment catalogue.',
                'descriptionAr' => 'بدء المدفوعات المستضافة في المتجر. تُضبط توفر المزودين في كتالوج المدفوعات.',
                'default' => true,
                'group' => 'commerce',
            ],
            [
                'key' => 'newsletter',
                'label' => 'Newsletter',
                'labelAr' => 'النشرة البريدية',
                'description' => 'Marketing newsletter capture, campaigns and unsubscribe flow.',
                'descriptionAr' => 'اشتراك النشرة التسويقية والحملات ومسار إلغاء الاشتراك.',
                'default' => true,
                'group' => 'growth',
            ],
            [
                'key' => 'help_center',
                'label' => 'Help Center',
                'labelAr' => 'مركز المساعدة',
                'description' => 'Published help articles, search and article feedback.',
                'descriptionAr' => 'مقالات المساعدة المنشورة والبحث وملاحظات المقالات.',
                'default' => true,
                'group' => 'growth',
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, labelAr: string, description: string, descriptionAr: string, default: bool, group: string}|null
     */
    public static function get(string $key): ?array
    {
        foreach (self::flags() as $flag) {
            if ($flag['key'] === $key) {
                return $flag;
            }
        }

        return null;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::flags(), 'key');
    }
}
