<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Models\FaqEntry;
use App\Models\PricingPlan;
use Illuminate\Database\Seeder;

/**
 * Public marketing content: pricing plans and FAQ entries.
 *
 * Idempotent by construction — `updateOrCreate` keyed on each row's natural
 * identifier (slug, question) — so a fresh seed and a re-seed converge on the
 * same content. No RNG and no `fake()` calls: two runs produce byte-identical
 * rows, like every other seeder in this directory.
 *
 * Honesty rules baked into this data:
 *   - plans are marketing positioning, not a billing contract: nothing is on
 *     sale during early access and no plan-specific limit is enforced;
 *   - a plan whose price has not been decided keeps a NULL price (the API
 *     surfaces that as coming soon rather than as zero);
 *   - every FAQ answer describes capability the product actually implements —
 *     no roadmap promises, no invented integrations, and no feature that only
 *     exists on a slide.
 */
class MarketingContentSeeder extends Seeder
{
    /**
     * Plan copy is positioning. The platform does not yet sell subscriptions,
     * assign plans or enforce per-plan entitlements, so a bullet must never
     * read as an exclusive right a merchant has paid for.
     *
     * @var list<array{slug: string, name: string, description: string, price: float|null, highlighted: bool, features: list<string>}>
     */
    private const array PLANS = [
        [
            'slug' => 'free',
            'name' => 'Free',
            'description' => 'Free during Dukavo early access. Every capability the platform ships today is included — there are no feature gates while paid plans are being finalised.',
            'price' => 0.00,
            'highlighted' => false,
            'features' => [
                'Catalogue, variants, categories and per-store inventory',
                'Orders, customers and the shared inbox across connected channels',
                'Public storefront, guest checkout and order tracking',
                'AI assistance for replies, summaries and translations',
                'Automation templates, analytics and team permissions',
                'Help Center and in-app notifications',
            ],
        ],
        [
            'slug' => 'growth',
            'name' => 'Growth',
            'description' => 'A planned plan for growing teams that run more than one store and need deeper automation and reporting. Pricing and any plan-specific limits will be announced before paid plans open; nothing is on sale yet.',
            'price' => null,
            'highlighted' => true,
            'features' => [
                'Everything available on the Free plan today',
                'Positioned for multi-store operations and deeper workflow control',
                'Positioned for richer reporting and analytics',
                'No plan-specific limits are enforced during early access',
            ],
        ],
        [
            'slug' => 'pro',
            'name' => 'Pro',
            'description' => 'A planned plan for larger operations managing several brands and stores. Pricing and any plan-specific limits will be announced before paid plans open; nothing is on sale yet.',
            'price' => null,
            'highlighted' => false,
            'features' => [
                'Everything positioned for the Growth plan',
                'Positioned for multi-brand operations',
                'Positioned for advanced profit and inventory analysis',
                'No plan-specific limits are enforced during early access',
            ],
        ],
    ];

    /**
     * Each answer is limited to what the code in this repository can do today.
     *
     * The Arabic copy is the same answer, not a shorter teaser: a visitor who
     * switches language should read the same commitment. It is stored in the
     * same row so a re-seed keeps both languages in step.
     *
     * The first six questions are the original rows; keeping their wording
     * identical means a re-seed updates them in place instead of leaving
     * duplicates behind. The rest are additive.
     *
     * @var list<array{question: string, question_ar: string, answer: string, answer_ar: string, category: string}>
     */
    private const array FAQS = [
        [
            'question' => 'Can I run more than one store?',
            'question_ar' => 'هل يمكنني تشغيل أكثر من متجر؟',
            'answer' => 'Yes. A tenant can hold several stores, each with its own inventory, currency, timezone and public storefront. You choose which store an order or product belongs to, and stock is tracked per store, so every location always knows its own levels.',
            'answer_ar' => 'نعم. يمكن للمستأجر أن يضم عدة متاجر، لكل منها مخزونه وعملته ومنطقته الزمنية وواجهته العامة. وأنت تحدد المتجر الذي ينتمي إليه الطلب أو المنتج، ويُتتبَّع المخزون لكل متجر، فيعرف كل موقع دائمًا مستوياته الخاصة.',
            'category' => 'general',
        ],
        [
            'question' => 'How does inventory tracking work?',
            'question_ar' => 'كيف يعمل تتبع المخزون؟',
            'answer' => 'Every product variant holds stock at a store. Adjustments, receives, transfers and sales all write a movement, so the ledger always explains how a quantity got where it is. Low-stock thresholds surface what needs reordering before it runs out.',
            'answer_ar' => 'كل متغيّر منتج يحتفظ بمخزونه في متجر. التعديلات والاستلام والتحويلات والمبيعات تُسجَّل جميعها كحركات، فيشرح السجل دائمًا كيف وصلت الكمية إلى ما هي عليه. وتُظهر حدود انخفاض المخزون ما يحتاج إلى إعادة طلب قبل نفاده.',
            'category' => 'general',
        ],
        [
            'question' => 'Does Dukavo connect to WhatsApp?',
            'question_ar' => 'هل يتصل دوكافو بواتساب؟',
            'answer' => 'Yes. You can connect a Meta WhatsApp Business account and reply to customers from the shared inbox. Messages arriving on that number become conversations tied to the customer, and message templates are available when the 24-hour reply window has closed. Connection requires an approved Meta business account and credentials.',
            'answer_ar' => 'نعم. يمكنك ربط حساب واتساب للأعمال من Meta والرد على العملاء من صندوق الوارد المشترك. الرسائل الواردة على ذلك الرقم تصبح محادثات مرتبطة بالعميل، وتتوفر قوالب الرسائل عند انتهاء نافذة الرد البالغة 24 ساعة. ويتطلب الربط حساب أعمال معتمدًا من Meta وبيانات اعتماد.',
            'category' => 'channels',
        ],
        [
            'question' => 'Can I control what my staff can access?',
            'question_ar' => 'هل يمكنني التحكم فيما يصل إليه فريقي؟',
            'answer' => 'Yes. Roles are built from permissions scoped per module — products, orders, inventory, conversations and the rest — so a stock clerk never sees billing settings and a support agent can reply to conversations without touching the catalogue.',
            'answer_ar' => 'نعم. تُبنى الأدوار من صلاحيات محددة لكل وحدة — المنتجات والطلبات والمخزون والمحادثات وغيرها — فلا يرى موظف المخزون إعدادات الفوترة، ويستطيع موظف الدعم الرد على المحادثات دون المساس بالكتالوج.',
            'category' => 'teams',
        ],
        [
            'question' => 'Is my data separated from other businesses?',
            'question_ar' => 'هل بياناتي منفصلة عن بيانات الأعمال الأخرى؟',
            'answer' => 'Completely. Every tenant is isolated at the database layer: products, orders, customers and conversations of one business are never visible to another, and every query is scoped to the tenant you are signed in to.',
            'answer_ar' => 'بالكامل. كل مستأجر معزول على مستوى قاعدة البيانات: منتجات وطلبات وعملاء ومحادثات أي عمل لا تكون مرئية لغيره، وكل استعلام محصور بالمستأجر الذي سجلت الدخول إليه.',
            'category' => 'security',
        ],
        [
            'question' => 'What does the Free plan include?',
            'question_ar' => 'ماذا تشمل الخطة المجانية؟',
            'answer' => 'During early access the Free plan covers the whole platform: catalogue, inventory, orders, customers, the shared inbox, storefront and guest checkout, AI assistance, automations, analytics and team permissions. Growth and Pro are planned packaging; their pricing and any plan-specific limits are not finalised and nothing is on sale yet.',
            'answer_ar' => 'خلال المرحلة التجريبية تشمل الخطة المجانية المنصة بأكملها: الكتالوج والمخزون والطلبات والعملاء وصندوق الوارد المشترك والواجهة العامة والدفع كزائر ومساعدة الذكاء الاصطناعي والأتمتة والتحليلات وصلاحيات الفريق. وخطتا Growth وPro هما تغليف مخطَّط؛ لم تُحدَّد أسعارهما أو أي حدود خاصة بهما، ولا شيء معروض للبيع بعد.',
            'category' => 'pricing',
        ],
        [
            'question' => 'What is Dukavo?',
            'question_ar' => 'ما هو دوكافو؟',
            'answer' => 'Dukavo is an AI-assisted commerce platform for merchants who sell through conversation and a storefront. It brings products, per-store inventory, orders, customers, a shared inbox across messaging channels, automations, analytics and a public shop into one tenant-scoped workspace.',
            'answer_ar' => 'دوكافو منصة تجارة بمساعدة الذكاء الاصطناعي للتجار الذين يبيعون عبر المحادثات والواجهة العامة. تجمع المنتجات والمخزون لكل متجر والطلبات والعملاء وصندوق وارد مشترك عبر قنوات المراسلة والأتمتة والتحليلات ومتجرًا عامًا في مساحة عمل واحدة معزولة لكل مستأجر.',
            'category' => 'general',
        ],
        [
            'question' => 'Which messaging channels can I connect?',
            'question_ar' => 'ما قنوات المراسلة التي يمكنني ربطها؟',
            'answer' => 'WhatsApp Business, Instagram Messaging, Facebook Messenger, Telegram and email are implemented as channels. Each needs its own provider account, credentials and — for the Meta channels — App Review. SMS is wired to a generic gateway but stays unavailable until a gateway is configured. TikTok and X are not available yet.',
            'answer_ar' => 'قنوات واتساب للأعمال وInstagram Messaging وFacebook Messenger وTelegram والبريد الإلكتروني منفَّذة كقنوات. وتحتاج كل قناة إلى حساب مزوّد وبيانات اعتماد خاصة بها، وإلى مراجعة التطبيق (App Review) لقنوات Meta. أما الرسائل النصية فهي مرتبطة ببوابة عامة لكنها تبقى غير متاحة حتى تُهيَّأ بوابة. ولا تتوفر TikTok وX بعد.',
            'category' => 'channels',
        ],
        [
            'question' => 'Can customers order from a public storefront?',
            'question_ar' => 'هل يمكن للعملاء الطلب من واجهة عامة؟',
            'answer' => 'Yes. Each store can publish its own public shop with a catalogue, product pages, policies, shipping zones, guest checkout and an unguessable order-tracking link. There is no public cart or theme editor yet, and carrier tracking is not included — delivery status is updated inside Dukavo.',
            'answer_ar' => 'نعم. يمكن لكل متجر نشر واجهته العامة بكتالوج وصفحات منتجات وسياسات ومناطق شحن ودفع كزائر ورابط تتبع طلب غير قابل للتخمين. ولا توجد بعد سلة عامة أو محرر قوالب، ولا يتضمن النظام تتبع شركات الشحن — تُحدَّث حالة التوصيل داخل دوكافو.',
            'category' => 'storefront',
        ],
        [
            'question' => 'Which payment methods are supported?',
            'question_ar' => 'ما طرق الدفع المدعومة؟',
            'answer' => 'Cash on delivery and bank transfer work without an external account. Online card payments go through a connected Tap Payments merchant account, which must be approved before card checkout can be enabled. MyFatoorah is listed in the provider catalogue but has no adapter yet.',
            'answer_ar' => 'يعمل الدفع عند الاستلام والتحويل البنكي دون حساب خارجي. أما مدفوعات البطاقات عبر الإنترنت فتمر عبر حساب تاجر معتمد لدى Tap Payments، ويجب اعتماده قبل تفعيل الدفع بالبطاقة. ومزوّد MyFatoorah مدرج في كتالوج المزوّدين لكنه لا يملك محوّلًا بعد.',
            'category' => 'payments',
        ],
        [
            'question' => 'How does the AI assistance work?',
            'question_ar' => 'كيف تعمل مساعدة الذكاء الاصطناعي؟',
            'answer' => 'AI helps draft replies and order proposals from live conversation context, summarise conversations and customers, brief you on dashboard figures, explain bundles, draft recovery follow-ups, suggest translations and transcribe voice notes. A platform administrator must configure a provider, usage limits are enforced per feature, and AI Actions always wait for a human to approve them.',
            'answer_ar' => 'يساعد الذكاء الاصطناعي في صياغة الردود ومقترحات الطلبات من سياق المحادثة المباشر، وتلخيص المحادثات والعملاء، وإيجاز أرقام لوحة التحكم، وشرح الحزم، وصياغة متابعات الاسترداد، واقتراح الترجمات، وتفريغ الملاحظات الصوتية. ويجب أن يهيّئ مسؤول المنصة مزوّدًا، وتُطبَّق حدود استخدام لكل ميزة، وتنتظر إجراءات الذكاء الاصطناعي دائمًا موافقة إنسان.',
            'category' => 'ai',
        ],
        [
            'question' => 'Does AI send messages or change data on its own?',
            'question_ar' => 'هل يرسل الذكاء الاصطناعي الرسائل أو يغيّر البيانات من تلقاء نفسه؟',
            'answer' => 'No. AI output is a suggestion. Sending a reply, applying a proposed order change or publishing a translation requires an explicit approval from a signed-in user with the right permission.',
            'answer_ar' => 'لا. مخرجات الذكاء الاصطناعي اقتراح. إرسال الرد أو تطبيق تغيير مقترح على الطلب أو نشر ترجمة يتطلب موافقة صريحة من مستخدم مسجل الدخول يملك الصلاحية المناسبة.',
            'category' => 'ai',
        ],
        [
            'question' => 'How do translations work?',
            'question_ar' => 'كيف تعمل الترجمات؟',
            'answer' => 'Catalogue, storefront, Help Center and other translatable content can be translated and reviewed. Only an approved translation is published, editing the source marks the translation stale, and AI can suggest a draft for a reviewer. The interface ships in English and Arabic; the backend accepts 25 locale codes, but only English and Arabic language packs are currently bundled.',
            'answer_ar' => 'يمكن ترجمة محتوى الكتالوج والواجهة العامة ومركز المساعدة وغيرها من المحتوى القابل للترجمة ومراجعته. ولا تُنشر إلا الترجمة المعتمدة، ويؤدي تعديل المصدر إلى وسم الترجمة بأنها قديمة، ويمكن للذكاء الاصطناعي اقتراح مسودة للمراجع. وتتوفر الواجهة بالإنجليزية والعربية؛ ويقبل الخادم 25 رمز لغة، لكن حزمتي الإنجليزية والعربية فقط مضمّنتان حاليًا.',
            'category' => 'translations',
        ],
        [
            'question' => 'What automations are available?',
            'question_ar' => 'ما الأتمتة المتاحة؟',
            'answer' => 'Automation templates cover common workflows such as order follow-up, low stock, abandoned orders, welcome messages and team handoffs. Installing a template creates a paused rule: you review the trigger, conditions and actions before enabling it. Custom rules can notify the team, create tasks or propose an AI action.',
            'answer_ar' => 'تغطي قوالب الأتمتة سير عمل شائعًا مثل متابعة الطلبات وانخفاض المخزون والطلبات المتروكة ورسائل الترحيب وتسليم المهام داخل الفريق. ويؤدي تثبيت القالب إلى إنشاء قاعدة متوقفة مؤقتًا: تراجع المشغّل والشروط والإجراءات قبل تفعيلها. ويمكن للقواعد المخصصة تنبيه الفريق أو إنشاء مهام أو اقتراح إجراء ذكاء اصطناعي.',
            'category' => 'automation',
        ],
        [
            'question' => 'Do product bundles work in the storefront?',
            'question_ar' => 'هل تعمل حزم المنتجات في الواجهة العامة؟',
            'answer' => 'Partly. Bundles can be prepared, priced and previewed inside the workspace, but bundle lines are not yet part of storefront checkout. Treat bundles as a workspace feature today, not a storefront selling option.',
            'answer_ar' => 'جزئيًا. يمكن إعداد الحزم وتسعيرها ومعاينتها داخل مساحة العمل، لكن بنود الحزم ليست بعد جزءًا من الدفع في الواجهة العامة. تعامل مع الحزم اليوم كميزة داخل مساحة العمل لا كخيار بيع في الواجهة.',
            'category' => 'catalogue',
        ],
        [
            'question' => 'Is there a mobile app?',
            'question_ar' => 'هل يوجد تطبيق جوال؟',
            'answer' => 'Yes. The Flutter app covers customer shopping, orders, payment retry, notifications, profile and the Help Center. Some merchant back-office screens remain web-first, so the mobile app is not yet a complete replacement for the web workspace.',
            'answer_ar' => 'نعم. يغطي تطبيق Flutter تسوّق العملاء والطلبات وإعادة محاولة الدفع والإشعارات والملف الشخصي ومركز المساعدة. وبعض شاشات لوحة التاجر لا تزال تُفضّل على الويب، لذا لا يُعدّ تطبيق الجوال بديلًا كاملًا عن مساحة العمل على الويب بعد.',
            'category' => 'mobile',
        ],
        [
            'question' => 'How do I get an account?',
            'question_ar' => 'كيف أحصل على حساب؟',
            'answer' => 'Onboarding is invite-only while the platform is in early access. Join the waitlist and the team reviews each request before sending an invitation. There is no self-serve sign-up form on the public site.',
            'answer_ar' => 'الانضمام بدعوة فقط أثناء المرحلة التجريبية. انضم إلى قائمة الانتظار ويراجع الفريق كل طلب قبل إرسال الدعوة. ولا يوجد نموذج تسجيل ذاتي في الموقع العام.',
            'category' => 'onboarding',
        ],
        [
            'question' => 'What languages does the platform support?',
            'question_ar' => 'ما اللغات التي تدعمها المنصة؟',
            'answer' => 'The web interface is available in English and Arabic, with full right-to-left layout support. Translatable content accepts 25 locale codes, but only English and Arabic language packs are bundled today, so other languages depend on adding a language pack.',
            'answer_ar' => 'تتوفر واجهة الويب بالإنجليزية والعربية مع دعم كامل للتخطيط من اليمين إلى اليسار. ويقبل المحتوى القابل للترجمة 25 رمز لغة، لكن حزمتي الإنجليزية والعربية فقط مضمّنتان اليوم، لذا تعتمد اللغات الأخرى على إضافة حزمة لغة.',
            'category' => 'translations',
        ],
        [
            'question' => 'Which notifications can I receive?',
            'question_ar' => 'ما الإشعارات التي يمكنني استلامها؟',
            'answer' => 'In-app and email notifications are available, with per-user preferences and a consent record. Push delivery on mobile requires Firebase credentials to be configured by the platform; without them, in-app and email still work.',
            'answer_ar' => 'تتوفر الإشعارات داخل التطبيق وعبر البريد الإلكتروني، مع تفضيلات لكل مستخدم وسجل موافقة. ويتطلب الدفع الفوري (Push) على الجوال تهيئة بيانات Firebase من المنصة؛ وبدونها يظل العمل داخل التطبيق وعبر البريد متاحًا.',
            'category' => 'notifications',
        ],
        [
            'question' => 'Is there a Help Center?',
            'question_ar' => 'هل يوجد مركز مساعدة؟',
            'answer' => 'Yes. Dukavo has a Help Center with categories, search, visibility tiers and article feedback. Some articles are public and some are available only after signing in.',
            'answer_ar' => 'نعم. لدى دوكافو مركز مساعدة يضم فئات وبحثًا ومستويات ظهور وتقييمًا للمقالات. بعض المقالات عامة وبعضها متاح فقط بعد تسجيل الدخول.',
            'category' => 'general',
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $index => $plan) {
            PricingPlan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'price' => $plan['price'],
                    'billing_interval' => BillingInterval::Monthly,
                    'currency' => 'USD',
                    'features' => $plan['features'],
                    'limits' => null,
                    'highlighted' => $plan['highlighted'],
                    'active' => true,
                    'sort_order' => $index * 10,
                ],
            );
        }

        foreach (self::FAQS as $index => $faq) {
            FaqEntry::query()->updateOrCreate(
                ['question' => $faq['question']],
                [
                    'question_ar' => $faq['question_ar'],
                    'answer' => $faq['answer'],
                    'answer_ar' => $faq['answer_ar'],
                    'category' => $faq['category'],
                    'sort_order' => $index * 10,
                    'active' => true,
                ],
            );
        }
    }
}
