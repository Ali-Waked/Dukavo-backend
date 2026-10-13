<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * The public long-form pages: terms, privacy and about.
 *
 * Each page exists once per supported language, so the same key has an English
 * and an Arabic row and the public endpoint serves the one the visitor asked
 * for. Idempotent by construction — `updateOrCreate` keyed on (`key`, `locale`)
 * — so a fresh seed and a re-seed converge on the same content and this file
 * stays the single source of truth for the initial copy. A platform
 * administrator edits the live rows afterwards; re-running the seeder
 * deliberately restores this baseline.
 *
 * Both languages carry the same document: the Arabic copy is a translation of
 * the English one, not a summary, so the two can be reviewed side by side. The
 * section ids are identical across languages because they are the heading
 * anchors, which must not change with the reader's language.
 *
 * Honesty rules baked into the data, because a policy that overclaims is worse
 * than no policy:
 *   - no legal entity, registration number, address, governing law or venue is
 *     invented — each is left as an obvious `[PLACEHOLDER]` for the business to
 *     complete before publication;
 *   - no security guarantee, certification or compliance claim is made;
 *   - cookies and local storage are described by what the code actually does;
 *   - no deletion window is promised, and the split between Dukavo-controlled
 *     and merchant-controlled data is stated plainly;
 *   - the About page describes only features that exist today and labels the
 *     rest as planned.
 */
class ContentPageSeeder extends Seeder
{
    /** Fixed so two runs produce byte-identical rows. */
    private const string PUBLISHED_AT = '2026-10-07 09:00:00';

    public function run(): void
    {
        foreach ($this->pages() as $page) {
            ContentPage::query()->updateOrCreate(
                ['key' => $page['key'], 'locale' => $page['locale']],
                [
                    'title' => $page['title'],
                    'intro' => $page['intro'],
                    'sections' => $page['sections'],
                    'active' => true,
                    'published_at' => self::PUBLISHED_AT,
                    'meta_title' => $page['meta_title'],
                    'meta_description' => $page['meta_description'],
                ],
            );
        }
    }

    /**
     * @return list<array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}>
     */
    private function pages(): array
    {
        return [
            $this->termsPage(),
            $this->termsPageAr(),
            $this->privacyPage(),
            $this->privacyPageAr(),
            $this->aboutPage(),
            $this->aboutPageAr(),
        ];
    }

    /**
     * @return array{id: string, title: string, layout: string, body: string}
     */
    private function section(string $id, string $title, string $layout, string $body): array
    {
        return ['id' => $id, 'title' => $title, 'layout' => $layout, 'body' => $body];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function termsPage(): array
    {
        return [
            'key' => 'terms',
            'locale' => 'en',
            'title' => 'Terms & Conditions',
            'intro' => 'The rules for using Dukavo, the platform that helps merchants run their store from one place.',
            'meta_title' => 'Terms & Conditions — Dukavo',
            'meta_description' => 'The terms that govern your use of the Dukavo merchant platform.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('acceptance', '1. Acceptance of these terms', 'prose', <<<'TEXT'
                These terms govern your access to and use of Dukavo, the software platform that helps merchants manage their catalogue, inventory, orders, customers and customer conversations from one place.

                By creating an account or using Dukavo, you agree to these terms. If you do not agree, please do not use the platform.

                > This document is an initial draft prepared for review. The details marked in square brackets must be completed, and the whole document reviewed by qualified counsel, before it is relied upon.
                TEXT),
                $this->section('who-we-are', '2. Who we are', 'prose', <<<'TEXT'
                Dukavo is operated by [LEGAL ENTITY NAME] ("Dukavo", "we", "us"), registered at [REGISTERED ADDRESS] under registration number [REGISTRATION NUMBER].

                You can contact us about these terms at [CONTACT EMAIL].
                TEXT),
                $this->section('accounts', '3. Accounts and eligibility', 'prose', <<<'TEXT'
                You need an account to use Dukavo. You must give accurate information when you register, keep it up to date, and be old enough to enter into a binding contract where you live.

                You are responsible for keeping your sign-in credentials confidential and for everything that happens under your account. Please tell us promptly at [CONTACT EMAIL] if you believe your account has been accessed without your permission.
                TEXT),
                $this->section('acceptable-use', '4. Acceptable use', 'prose', <<<'TEXT'
                You may use Dukavo only for lawful business purposes. Our Acceptable Use Policy describes the behaviour we do not permit, and it forms part of these terms.

                In particular, you must not use Dukavo to break the law, to infringe the rights of others, to send unlawful or unsolicited messages through connected channels, or to probe, disrupt or attempt to reverse engineer the platform.
                TEXT),
                $this->section('your-data', '5. Your data and content', 'prose', <<<'TEXT'
                Your workspace holds the data you and your team put into Dukavo: products, stock, orders, customer records and conversations ("Merchant Content"). As between you and Dukavo, you own your Merchant Content.

                We process your Merchant Content only to provide and support the platform, as described in our Privacy Policy. You are responsible for having the right to store the data you enter, including any personal data about your own customers.
                TEXT),
                $this->section('connected-services', '6. Connected services', 'prose', <<<'TEXT'
                Dukavo can connect to services run by other companies, such as WhatsApp. Those services are governed by their own terms and privacy policies, and your use of them through Dukavo is also subject to those terms.

                We do not control services we do not operate, and a change on their side may affect what Dukavo can do.
                TEXT),
                $this->section('plans-and-fees', '7. Plans and fees', 'prose', <<<'TEXT'
                Dukavo is currently free to use during early access. Paid plans are described on our pricing page but are not yet on sale: online billing and checkout are not enabled, so no payment can be taken for Dukavo today.

                If we introduce paid plans, we will publish the prices and terms before you are asked to pay anything.
                TEXT),
                $this->section('availability', '8. Availability and changes', 'prose', <<<'TEXT'
                Dukavo is under active development. Features may be added, changed or removed, and the platform may occasionally be unavailable for maintenance or because of a fault.

                Some capabilities shown on our website are labelled as planned. They are not part of the service until they are released.
                TEXT),
                $this->section('intellectual-property', '9. Intellectual property', 'prose', <<<'TEXT'
                We and our licensors own Dukavo, including its software, design, and the Dukavo name and logo. These terms do not grant you any right to use our brand without our written permission.

                If you send us suggestions or feedback, we may use them to improve the platform without any obligation to you.
                TEXT),
                $this->section('termination', '10. Suspension and termination', 'prose', <<<'TEXT'
                You may stop using Dukavo at any time, and you can ask us to close your account at [CONTACT EMAIL].

                We may suspend or end your access if you materially breach these terms, if the law requires it, or if continuing would put the platform or other users at risk. Where it is reasonable to do so, we will tell you why.
                TEXT),
                $this->section('disclaimers', '11. Disclaimers', 'prose', <<<'TEXT'
                Dukavo is provided on an "as is" and "as available" basis. To the extent permitted by law, we make no warranty that the platform will be uninterrupted, error-free, or fit for a particular purpose.

                Dukavo is business software. It is not legal, tax or accounting advice, and you remain responsible for complying with the laws that apply to your business.
                TEXT),
                $this->section('liability', '12. Limitation of liability', 'prose', <<<'TEXT'
                To the extent permitted by law, Dukavo is not liable for indirect or consequential losses, or for loss of profit, revenue, data or goodwill.

                Nothing in these terms limits liability that cannot be limited by law. The exact limits that apply to you depend on [GOVERNING LAW], which must be completed and reviewed before publication.
                TEXT),
                $this->section('governing-law', '13. Governing law and disputes', 'prose', <<<'TEXT'
                These terms are governed by the laws of [GOVERNING LAW / JURISDICTION], and disputes will be handled by the courts of [VENUE].

                If any part of these terms is found to be unenforceable, the rest continues to apply.
                TEXT),
                $this->section('changes', '14. Changes to these terms', 'prose', <<<'TEXT'
                We may update these terms as the platform evolves. When we make a material change, we will give notice on the platform or by email before it takes effect, and we will update the date shown at the top of this page.
                TEXT),
                $this->section('contact', '15. Contact', 'prose', <<<'TEXT'
                Questions about these terms can be sent to [CONTACT EMAIL].
                TEXT),
            ],
        ];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function termsPageAr(): array
    {
        return [
            'key' => 'terms',
            'locale' => 'ar',
            'title' => 'الشروط والأحكام',
            'intro' => 'القواعد التي تنظّم استخدامك لمنصة Dukavo التي تساعد التجار على إدارة متجرهم من مكان واحد.',
            'meta_title' => 'الشروط والأحكام — Dukavo',
            'meta_description' => 'الشروط التي تنظّم استخدامك لمنصة Dukavo للتجار.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('acceptance', '1. قبول هذه الشروط', 'prose', <<<'TEXT'
                تنظّم هذه الشروط وصولك إلى Dukavo واستخدامك لها، وهي المنصة البرمجية التي تساعد التجار على إدارة الكتالوج والمخزون والطلبات والعملاء ومحادثات العملاء من مكان واحد.

                بإنشاء حساب أو باستخدام Dukavo، فإنك توافق على هذه الشروط. وإذا لم توافق عليها، فيُرجى عدم استخدام المنصة.

                > هذا المستند مسودة أولية أُعدّت للمراجعة. يجب استكمال التفاصيل الموضوعة بين قوسين مربّعين، ومراجعة المستند بالكامل من مستشار قانوني مؤهّل، قبل الاعتماد عليه.
                TEXT),
                $this->section('who-we-are', '2. من نحن', 'prose', <<<'TEXT'
                تُدار Dukavo بواسطة [اسم الكيان القانوني] ("Dukavo"، "نحن")، والمسجّلة في [العنوان المسجّل] برقم تسجيل [رقم التسجيل].

                ويمكنك التواصل معنا بشأن هذه الشروط عبر [البريد الإلكتروني للتواصل].
                TEXT),
                $this->section('accounts', '3. الحسابات وأهلية الاستخدام', 'prose', <<<'TEXT'
                تحتاج إلى حساب لاستخدام Dukavo. ويجب أن تقدّم معلومات دقيقة عند التسجيل، وأن تحافظ على تحديثها، وأن يكون عمرك كافيًا لإبرام عقد مُلزم في المكان الذي تقيم فيه.

                أنت مسؤول عن الحفاظ على سرية بيانات تسجيل الدخول وعن كل ما يجري عبر حسابك. ويُرجى إبلاغنا فورًا على [البريد الإلكتروني للتواصل] إذا اعتقدت أن أحدًا وصل إلى حسابك دون إذنك.
                TEXT),
                $this->section('acceptable-use', '4. الاستخدام المقبول', 'prose', <<<'TEXT'
                لا يجوز لك استخدام Dukavo إلا لأغراض تجارية مشروعة. وتوضّح سياسة الاستخدام المقبول الخاصة بنا السلوكيات التي لا نسمح بها، وهي جزء من هذه الشروط.

                وعلى وجه الخصوص، يجب ألا تستخدم Dukavo لمخالفة القانون، أو للاعتداء على حقوق الآخرين، أو لإرسال رسائل غير قانونية أو غير مرغوب فيها عبر القنوات المتصلة، أو لسَبْر المنصة أو تعطيلها أو محاولة هندستها عكسيًا.
                TEXT),
                $this->section('your-data', '5. بياناتك ومحتواك', 'prose', <<<'TEXT'
                تحتوي مساحة عملك على البيانات التي تضعها أنت وفريقك في Dukavo: المنتجات والمخزون والطلبات وسجلات العملاء والمحادثات ("محتوى التاجر"). وفيما بينك وبين Dukavo، تبقى مالكًا لمحتوى التاجر الخاص بك.

                نعالج محتوى التاجر الخاص بك فقط لتقديم المنصة ودعمها، على النحو الموضّح في سياسة الخصوصية. وأنت مسؤول عن امتلاكك الحق في تخزين البيانات التي تُدخلها، بما في ذلك أي بيانات شخصية تخصّ عملاءك.
                TEXT),
                $this->section('connected-services', '6. الخدمات المتصلة', 'prose', <<<'TEXT'
                يمكن لـ Dukavo الاتصال بخدمات تديرها شركات أخرى، مثل WhatsApp. وتخضع تلك الخدمات لشروطها وسياسات الخصوصية الخاصة بها، ويخضع استخدامك لها عبر Dukavo لتلك الشروط أيضًا.

                نحن لا نتحكم في خدمات لا نديرها، وقد يؤثر أي تغيير من جانبها على ما تستطيع Dukavo فعله.
                TEXT),
                $this->section('plans-and-fees', '7. الخطط والرسوم', 'prose', <<<'TEXT'
                منصة Dukavo مجانية الاستخدام حاليًا خلال المرحلة المبكرة. الخطط المدفوعة موصوفة في صفحة الأسعار لكنها ليست معروضة للبيع بعد: الفوترة والدفع الإلكتروني غير مُفعّلين، لذا لا يمكن تحصيل أي مبلغ مقابل Dukavo اليوم.

                وإذا طرحنا خططًا مدفوعة، فسننشر الأسعار والشروط قبل أن يُطلب منك أي دفع.
                TEXT),
                $this->section('availability', '8. التوفر والتغييرات', 'prose', <<<'TEXT'
                تخضع Dukavo لتطوير نشط. وقد تُضاف المزايا أو تُعدّل أو تُزال، وقد تنقطع المنصة أحيانًا عن العمل لأغراض الصيانة أو بسبب عطل.

                بعض الإمكانات المعروضة على موقعنا موسومة بأنها مخطّط لها، وهي ليست جزءًا من الخدمة حتى يتم إطلاقها.
                TEXT),
                $this->section('intellectual-property', '9. الملكية الفكرية', 'prose', <<<'TEXT'
                نحن ومرخّصونا نملك Dukavo، بما في ذلك برمجتها وتصميمها واسم Dukavo وشعارها. ولا تمنحك هذه الشروط أي حق في استخدام علامتنا التجارية دون إذن كتابي منا.

                وإذا أرسلت إلينا اقتراحات أو ملاحظات، فيجوز لنا استخدامها لتحسين المنصة دون أي التزام تجاهك.
                TEXT),
                $this->section('termination', '10. الإيقاف والإنهاء', 'prose', <<<'TEXT'
                يمكنك التوقف عن استخدام Dukavo في أي وقت، ويمكنك أن تطلب إغلاق حسابك عبر [البريد الإلكتروني للتواصل].

                ويجوز لنا إيقاف وصولك أو إنهاؤه إذا خالفت هذه الشروط مخالفة جوهرية، أو إذا اقتضى القانون ذلك، أو إذا كان استمرار وصولك سيعرّض المنصة أو المستخدمين الآخرين للخطر. وسنوضّح لك السبب حيث يكون ذلك معقولًا.
                TEXT),
                $this->section('disclaimers', '11. إخلاء المسؤولية', 'prose', <<<'TEXT'
                تُقدَّم Dukavo على أساس "كما هي" و"حسب توفرها". وإلى الحد الذي يسمح به القانون، لا نقدّم أي ضمان بأن تكون المنصة متواصلة بلا انقطاع أو خالية من الأخطاء أو ملائمة لغرض معيّن.

                Dukavo برنامج أعمال، وليست مشورة قانونية أو ضريبية أو محاسبية، وتبقى مسؤولًا عن الامتثال للقوانين التي تنطبق على نشاطك التجاري.
                TEXT),
                $this->section('liability', '12. حدود المسؤولية', 'prose', <<<'TEXT'
                إلى الحد الذي يسمح به القانون، لا تتحمّل Dukavo المسؤولية عن الخسائر غير المباشرة أو التبعية، أو عن فقدان الأرباح أو الإيرادات أو البيانات أو السمعة.

                ولا يحُدّ أي مما في هذه الشروط من المسؤولية التي لا يجوز الحدّ منها قانونًا. وتتوقف الحدود الفعلية التي تنطبق عليك على [القانون الواجب التطبيق]، والذي يجب استكماله ومراجعته قبل النشر.
                TEXT),
                $this->section('governing-law', '13. القانون الواجب التطبيق وتسوية النزاعات', 'prose', <<<'TEXT'
                تخضع هذه الشروط لقوانين [القانون الواجب التطبيق / الاختصاص القضائي]، وتُحال النزاعات إلى محاكم [الجهة القضائية المختصة].

                وإذا تبيّن أن أي جزء من هذه الشروط غير قابل للتنفيذ، فإن بقية الشروط تظل سارية.
                TEXT),
                $this->section('changes', '14. التغييرات على هذه الشروط', 'prose', <<<'TEXT'
                يجوز لنا تحديث هذه الشروط مع تطور المنصة. وعندما نجري تغييرًا جوهريًا، سنخطرك على المنصة أو بالبريد الإلكتروني قبل أن يصبح ساريًا، وسنحدّث التاريخ الظاهر في أعلى هذه الصفحة.
                TEXT),
                $this->section('contact', '15. التواصل', 'prose', <<<'TEXT'
                يمكن إرسال الأسئلة المتعلقة بهذه الشروط إلى [البريد الإلكتروني للتواصل].
                TEXT),
            ],
        ];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function privacyPage(): array
    {
        return [
            'key' => 'privacy',
            'locale' => 'en',
            'title' => 'Privacy Policy',
            'intro' => 'How Dukavo collects, uses and protects information when you use the platform.',
            'meta_title' => 'Privacy Policy — Dukavo',
            'meta_description' => 'How Dukavo collects, uses and protects information.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('about', '1. About this policy', 'prose', <<<'TEXT'
                This policy explains what information Dukavo collects, why we collect it, and how we handle it. It covers the Dukavo website and the Dukavo merchant platform.

                Dukavo is operated by [LEGAL ENTITY NAME]. Under data protection law, we are the controller of the information described in sections 2 to 4, and we act as a processor for the customer data described in section 6.

                > This document is an initial draft prepared for review. The details marked in square brackets must be completed, and the whole document reviewed by qualified counsel, before it is relied upon.
                TEXT),
                $this->section('what-we-collect', '2. What we collect', 'prose', <<<'TEXT'
                Account information: when you register, we collect your name and email address, and we store your password in hashed form only — never as plain text.

                Merchant Content: the products, stock, orders, customer records and conversations you and your team put into Dukavo.

                Usage and device information: information needed to run and secure the platform, such as the time of a request, the workspace it was for, and basic technical details about the connection.

                Preferences: your chosen language and theme, and the fact that you are signed in, are kept in your browser.
                TEXT),
                $this->section('how-we-use', '3. How we use information', 'prose', <<<'TEXT'
                We use information to:

                - provide the platform and the features you use;
                - keep your account secure and sign you in;
                - respond to your questions and support requests;
                - operate, maintain and improve the platform;
                - meet our legal, tax and accounting obligations.
                TEXT),
                $this->section('legal-basis', '4. Why we are allowed to process it', 'prose', <<<'TEXT'
                Where the law requires a legal basis for processing, we rely on performing our contract with you, on our legitimate interests in running and securing the platform, on your consent where we ask for it, and on compliance with our legal obligations.

                The exact bases that apply to you depend on [GOVERNING LAW] and must be reviewed before publication.
                TEXT),
                $this->section('sharing', '5. When we share information', 'prose', <<<'TEXT'
                We share information with service providers who help us run Dukavo, such as hosting and email providers, and only as needed for them to provide their service to us. We do not sell your personal information.

                We may also disclose information where the law requires it, or to protect the rights and safety of Dukavo, our users or others.
                TEXT),
                $this->section('your-customers', '6. Information about your customers', 'prose', <<<'TEXT'
                When you store data about your own customers in Dukavo, you are the controller of that data and Dukavo is your processor: we handle it on your instructions to provide the platform.

                If one of your customers contacts us about their data, we will refer them to you, because you control their relationship and records.
                TEXT),
                $this->section('retention', '7. How long we keep information', 'prose', <<<'TEXT'
                We keep your account and workspace data for as long as your account is open. When you ask us to close your account, we delete or anonymise your data, except where we must keep it to meet a legal obligation or to resolve a dispute.

                We keep support and contact messages for as long as needed to handle them, and to keep a record of the exchange.
                TEXT),
                $this->section('security', '8. Security', 'prose', <<<'TEXT'
                We take reasonable steps to protect information, including limiting who can access it, keeping each workspace's data separate from others at the database layer, and storing credentials in hashed form.

                No system is completely secure, and we cannot guarantee absolute security. Please use a strong, unique password and tell us if you suspect unauthorised access.
                TEXT),
                $this->section('your-rights', '9. Your rights', 'prose', <<<'TEXT'
                Depending on where you live, you may have the right to see, correct, delete or receive a copy of your personal information, and to object to or restrict some processing.

                To exercise these rights, contact us at [PRIVACY EMAIL]. If you are a customer of one of our merchants, please contact that merchant directly, as they control your data.
                TEXT),
                $this->section('transfers', '10. International transfers', 'prose', <<<'TEXT'
                Dukavo may process information in countries other than your own. Where we transfer personal information across borders, we use the safeguards required by applicable law.

                The details depend on [GOVERNING LAW] and must be reviewed before publication.
                TEXT),
                $this->section('cookies', '11. Cookies and local storage', 'prose', <<<'TEXT'
                We use a session cookie to keep you signed in, and we store your theme and language preferences in your browser's local storage. These are needed for the platform to work as you expect.

                We do not currently run third-party advertising trackers. If that changes, we will update this policy first.
                TEXT),
                $this->section('children', '12. Children', 'prose', <<<'TEXT'
                Dukavo is a business tool and is not intended for children. We do not knowingly collect information from children, and the minimum age to use Dukavo is [MINIMUM AGE], which must be set for the jurisdictions we serve.
                TEXT),
                $this->section('changes', '13. Changes to this policy', 'prose', <<<'TEXT'
                We may update this policy as the platform and the law change. We will update the date shown at the top of this page, and we will give notice of material changes before they take effect.
                TEXT),
                $this->section('contact', '14. Contact us', 'prose', <<<'TEXT'
                Questions about privacy can be sent to [PRIVACY EMAIL].
                TEXT),
            ],
        ];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function privacyPageAr(): array
    {
        return [
            'key' => 'privacy',
            'locale' => 'ar',
            'title' => 'سياسة الخصوصية',
            'intro' => 'كيف تجمع Dukavo المعلومات وتستخدمها وتحميها عند استخدامك للمنصة.',
            'meta_title' => 'سياسة الخصوصية — Dukavo',
            'meta_description' => 'كيف تجمع Dukavo المعلومات وتستخدمها وتحميها.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('about', '1. عن هذه السياسة', 'prose', <<<'TEXT'
                توضّح هذه السياسة ما تجمعه Dukavo من معلومات، ولماذا تجمعها، وكيف نتعامل معها. وهي تغطي موقع Dukavo ومنصة Dukavo للتجار.

                تُدار Dukavo بواسطة [اسم الكيان القانوني]. وبموجب قانون حماية البيانات، نحن المتحكّم في المعلومات الموضّحة في الأقسام 2 إلى 4، ونعمل كمعالج لبيانات العملاء الموضّحة في القسم 6.

                > هذا المستند مسودة أولية أُعدّت للمراجعة. يجب استكمال التفاصيل الموضوعة بين قوسين مربّعين، ومراجعة المستند بالكامل من مستشار قانوني مؤهّل، قبل الاعتماد عليه.
                TEXT),
                $this->section('what-we-collect', '2. ما الذي نجمعه', 'prose', <<<'TEXT'
                معلومات الحساب: عند التسجيل، نجمع اسمك وبريدك الإلكتروني، ونخزّن كلمة المرور بصيغة مُجزّأة فقط، وليس كنص صريح أبدًا.

                محتوى التاجر: المنتجات والمخزون والطلبات وسجلات العملاء والمحادثات التي تضيفها أنت وفريقك إلى Dukavo.

                معلومات الاستخدام والجهاز: معلومات لازمة لتشغيل المنصة وتأمينها، مثل وقت الطلب، ومساحة العمل التي كان الطلب من أجلها، وتفاصيل تقنية أساسية عن الاتصال.

                التفضيلات: تُحفظ اللغة والمظهر اللذان اخترتهما، وكونك مسجّل الدخول، في متصفحك.
                TEXT),
                $this->section('how-we-use', '3. كيف نستخدم المعلومات', 'prose', <<<'TEXT'
                نستخدم المعلومات من أجل:

                - تشغيل المنصة والمزايا التي تستخدمها؛
                - الحفاظ على أمان حسابك وتسجيل دخولك؛
                - الرد على أسئلتك وطلبات الدعم؛
                - تشغيل المنصة وصيانتها وتحسينها؛
                - الوفاء بالتزاماتنا القانونية والضريبية والمحاسبية.
                TEXT),
                $this->section('legal-basis', '4. الأساس الذي يسمح لنا بالمعالجة', 'prose', <<<'TEXT'
                حيثما يشترط القانون وجود أساس للمعالجة، فإننا نعتمد على تنفيذ عقدنا معك، وعلى مصالحنا المشروعة في تشغيل المنصة وتأمينها، وعلى موافقتك حيثما نطلبها، وعلى الامتثال لالتزاماتنا القانونية.

                ويعتمد الأساس الفعلي المطبّق عليك على [القانون الواجب التطبيق]، ويجب مراجعته قبل النشر.
                TEXT),
                $this->section('sharing', '5. متى نشارك المعلومات', 'prose', <<<'TEXT'
                نشارك المعلومات مع مزوّدي الخدمات الذين يساعدوننا في تشغيل Dukavo، مثل مزوّدي الاستضافة والبريد الإلكتروني، وبالقدر اللازم فقط لأدائهم خدماتهم لنا. ونحن لا نبيع معلوماتك الشخصية.

                وقد نفصح عن المعلومات أيضًا عندما يقتضي القانون ذلك، أو لحماية حقوق وسلامة Dukavo أو مستخدمينا أو غيرهم.
                TEXT),
                $this->section('your-customers', '6. معلومات عن عملائك', 'prose', <<<'TEXT'
                عندما تخزّن في Dukavo بيانات تخصّ عملاءك أنت، فأنت المتحكّم في تلك البيانات وتعمل Dukavo كمعالج لك: نتولى معالجتها بناءً على تعليماتك لتقديم المنصة.

                وإذا تواصل معنا أحد عملائك بشأن بياناته، فسنوجّهه إليك، لأن العلاقة والسجلات تحت سيطرتك.
                TEXT),
                $this->section('retention', '7. مدة الاحتفاظ بالمعلومات', 'prose', <<<'TEXT'
                نحتفظ ببيانات حسابك ومساحة عملك ما دام حسابك مفتوحًا. وعندما تطلب إغلاق حسابك، نحذف بياناتك أو نجعلها مجهولة الهوية، إلا حيث يجب علينا الاحتفاظ بها للوفاء بالتزام قانوني أو لحل نزاع.

                كما نحتفظ برسائل الدعم والتواصل للمدة اللازمة للتعامل معها وللاحتفاظ بسجل للمراسلة.
                TEXT),
                $this->section('security', '8. الأمان', 'prose', <<<'TEXT'
                نتخذ خطوات معقولة لحماية المعلومات، بما في ذلك تحديد من يمكنه الوصول إليها، وإبقاء بيانات كل مساحة عمل منفصلة عن غيرها على مستوى قاعدة البيانات، وتخزين بيانات الاعتماد بصيغة مُجزّأة.

                ولا يوجد نظام آمن تمامًا، ولا يمكننا ضمان أمان مطلق. ويرجى استخدام كلمة مرور قوية وفريدة، وإخبارنا إذا شككت في وجود وصول غير مصرّح به.
                TEXT),
                $this->section('your-rights', '9. حقوقك', 'prose', <<<'TEXT'
                بحسب المكان الذي تعيش فيه، قد يكون لك الحق في الاطّلاع على معلوماتك الشخصية أو تصحيحها أو حذفها أو الحصول على نسخة منها، وفي الاعتراض على بعض أوجه المعالجة أو تقييدها.

                ولممارسة هذه الحقوق، تواصل معنا على [البريد الإلكتروني للخصوصية]. وإذا كنت عميلًا لأحد تجّارنا، فيرجى التواصل مع ذلك التاجر مباشرة، لأنه المتحكّم في بياناتك.
                TEXT),
                $this->section('transfers', '10. عمليات النقل الدولية', 'prose', <<<'TEXT'
                قد تعالج Dukavo المعلومات في بلدان غير بلدك. وعندما ننقل المعلومات الشخصية عبر الحدود، فإننا نستخدم الضمانات التي يقتضيها القانون الواجب التطبيق.

                وتعتمد التفاصيل على [القانون الواجب التطبيق] ويجب مراجعتها قبل النشر.
                TEXT),
                $this->section('cookies', '11. ملفات تعريف الارتباط والتخزين المحلي', 'prose', <<<'TEXT'
                نستخدم ملف تعريف ارتباط للجلسة لإبقائك مسجّل الدخول، ونحفظ تفضيلات المظهر واللغة في التخزين المحلي لمتصفحك. وهذه العناصر لازمة لتعمل المنصة كما تتوقع.

                ولا نُشغّل حاليًا أي أدوات تتبّع إعلانية من أطراف ثالثة. وإذا تغيّر ذلك، فسنحدّث هذه السياسة أولًا.
                TEXT),
                $this->section('children', '12. الأطفال', 'prose', <<<'TEXT'
                Dukavo أداة أعمال وليست موجّهة للأطفال. ونحن لا نجمع معلومات من الأطفال عن قصد، والحد الأدنى للسن لاستخدام Dukavo هو [الحد الأدنى للعمر]، والذي يجب تحديده بحسب الولايات القضائية التي نخدمها.
                TEXT),
                $this->section('changes', '13. التغييرات على هذه السياسة', 'prose', <<<'TEXT'
                يجوز لنا تحديث هذه السياسة مع تغيّر المنصة والقانون. وسنحدّث التاريخ الظاهر في أعلى هذه الصفحة، وسنخطرك بالتغييرات الجوهرية قبل أن تصبح سارية.
                TEXT),
                $this->section('contact', '14. تواصل معنا', 'prose', <<<'TEXT'
                يمكن إرسال الأسئلة المتعلقة بالخصوصية إلى [البريد الإلكتروني للخصوصية].
                TEXT),
            ],
        ];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function aboutPage(): array
    {
        return [
            'key' => 'about',
            'locale' => 'en',
            'title' => 'About Dukavo',
            'intro' => 'Dukavo is built for merchants who run their business through conversation, and need the operational backbone to keep it in order.',
            'meta_title' => 'About Dukavo',
            'meta_description' => 'Why Dukavo exists and how it helps merchants run their store from one place.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('why', 'Why Dukavo exists', 'prose', <<<'TEXT'
                Many growing merchants run their whole business through chat and a spreadsheet. It works — until it doesn't. Orders are lost in the scroll, stock numbers stop matching reality, and the history of a customer relationship lives in someone's phone.

                Dukavo exists to give that kind of business a real operating system: one place to run products, stock, orders, customers and conversations, without forcing it into software built for somebody else.
                TEXT),
                $this->section('what-we-do', 'What Dukavo does today', 'prose', <<<'TEXT'
                Dukavo brings the day-to-day running of a store into one workspace.

                You manage a catalogue of products, variants, categories and images. You track stock per store with a full movement history and low-stock alerts. You run orders through their lifecycle from pending to delivered, keep customer records with their order history, and handle customer conversations — including WhatsApp — from a shared inbox. Roles and permissions let you decide exactly what each member of your team can see and do.
                TEXT),
                $this->section('how-it-works', 'How it works', 'steps', <<<'TEXT'
                Create your workspace: Sign up and set up the business you are going to run.
                Add your catalogue: Bring in your products, prices and images.
                Stock your stores: Record what you have on hand in each store.
                Take orders: Track every order from pending through to delivery.
                Connect WhatsApp: Link your number and manage conversations from the shared inbox.
                Invite your team: Add staff and give each person the permissions they need.
                TEXT),
                $this->section('beliefs', 'What we believe', 'cards', <<<'TEXT'
                Your data is yours: You own what is in your workspace, and we build the product to keep it that way.
                Clarity over clutter: Every screen should answer a real question, not add noise.
                Boring where it matters: Orders, stock and money need to be predictable, not clever.
                Honest by default: We describe what the product does today, and label what is still to come.
                TEXT),
                $this->section('roadmap', 'Where Dukavo is going', 'prose', <<<'TEXT'
                Dukavo is in active development. Online payments and billing, deeper reporting and additional sales channels are planned but not yet available, and anything that is not released is labelled as planned.

                We would rather ship a smaller product that works than promise features that are not ready.
                TEXT),
                $this->section('get-started', 'Start with Dukavo', 'cta', <<<'TEXT'
                Create an account to explore the platform, or get in touch and tell us what your business needs.
                TEXT),
            ],
        ];
    }

    /**
     * @return array{key: string, locale: string, title: string, intro: string, meta_title: string, meta_description: string, published_at: string, sections: list<array{id: string, title: string, layout: string, body: string}>}
     */
    private function aboutPageAr(): array
    {
        return [
            'key' => 'about',
            'locale' => 'ar',
            'title' => 'عن Dukavo',
            'intro' => 'صُمّم Dukavo للتجار الذين يديرون أعمالهم عبر المحادثات، ويحتاجون إلى العمود الفقري التشغيلي الذي يحفظ لها نظامها.',
            'meta_title' => 'عن Dukavo',
            'meta_description' => 'لماذا وُجد Dukavo وكيف يساعد التجار على إدارة متجرهم من مكان واحد.',
            'published_at' => self::PUBLISHED_AT,
            'sections' => [
                $this->section('why', 'لماذا وُجد Dukavo', 'prose', <<<'TEXT'
                كثير من التجار في مرحلة النمو يديرون أعمالهم بالكامل عبر المحادثات وجدول بيانات. وهذا ينجح، إلى أن يتوقف عن النجاح. تضيع الطلبات وسط الرسائل، وتتوقف أرقام المخزون عن مطابقة الواقع، ويصبح تاريخ علاقة العميل محصورًا في هاتف أحدهم.

                وُجد Dukavo ليمنح هذا النوع من الأعمال نظام تشغيل حقيقيًا: مكان واحد لإدارة المنتجات والمخزون والطلبات والعملاء والمحادثات، دون فرض برمجيات صُمّمت لشخص آخر.
                TEXT),
                $this->section('what-we-do', 'ما الذي يفعله Dukavo اليوم', 'prose', <<<'TEXT'
                يجمع Dukavo إدارة المتجر اليومية في مساحة عمل واحدة.

                فأنت تدير كتالوجًا من المنتجات والمتغيّرات والفئات والصور. وتتبّع المخزون لكل متجر مع سجل حركة كامل وتنبيهات انخفاض المخزون. وتدير الطلبات عبر مراحلها من الانتظار حتى التسليم، وتحتفظ بسجلات العملاء مع تاريخ طلباتهم، وتتعامل مع محادثات العملاء — بما في ذلك WhatsApp — من صندوق وارد مشترك. وتتيح لك الأدوار والصلاحيات تحديد ما يمكن لكل عضو في فريقك رؤيته والقيام به بدقة.
                TEXT),
                $this->section('how-it-works', 'كيف يعمل', 'steps', <<<'TEXT'
                أنشئ مساحة عملك: سجّل وأنشئ النشاط الذي ستديره.
                أضف كتالوجك: أدخل منتجاتك وأسعارها وصورها.
                جهّز مخزون متاجرك: سجّل ما لديك في كل متجر.
                استقبل الطلبات: تتبّع كل طلب من الانتظار حتى التسليم.
                اربط WhatsApp: اربط رقمك وأدر المحادثات من صندوق الوارد المشترك.
                ادعُ فريقك: أضف الموظفين وامنح كل شخص الصلاحيات التي يحتاجها.
                TEXT),
                $this->section('beliefs', 'ما نؤمن به', 'cards', <<<'TEXT'
                بياناتك ملكك: أنت تملك ما في مساحة عملك، ونبني المنتج على هذا الأساس.
                الوضوح قبل الازدحام: كل شاشة يجب أن تجيب عن سؤال حقيقي، لا أن تضيف ضجيجًا.
                البساطة حيث تهمّ: الطلبات والمخزون والمال يجب أن تكون متوقّعة، لا بارعة.
                الصدق افتراضيًا: نصف ما يفعله المنتج اليوم، ونوسم ما لم يصل بعد.
                TEXT),
                $this->section('roadmap', 'إلى أين يتجه Dukavo', 'prose', <<<'TEXT'
                يخضع Dukavo لتطوير نشط. الدفع والفوترة الإلكترونية، وتقارير أعمق، وقنوات بيع إضافية، مخطّط لها لكنها غير متاحة بعد، وكل ما لم يُطلق يُوسم بأنه مخطّط له.

                ونفضّل إطلاق منتج أصغر يعمل فعليًا على أن نَعِد بمزايا غير جاهزة.
                TEXT),
                $this->section('get-started', 'ابدأ مع Dukavo', 'cta', <<<'TEXT'
                أنشئ حسابًا لاستكشاف المنصة، أو تواصل معنا وأخبرنا بما يحتاجه نشاطك.
                TEXT),
            ],
        ];
    }
}
