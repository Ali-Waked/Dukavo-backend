<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * The initial Dukavo Help Center.
 *
 * Every article documents behaviour that exists in the code today, condensed
 * from the merchant guide in the repository documentation (`docs/02-merchant-guide/`).
 * No article describes a planned or imagined feature: the seeded Help Center
 * follows the same truth rule as the documentation it condenses.
 *
 * The list is data, not logic: the seeder owns persistence, so an editor can
 * review the copy without reading database code. Slugs are fixed so re-seeding
 * is idempotent, and Arabic is seeded for the getting-started set only — the
 * remaining articles demonstrate the documented fallback: an untranslated
 * article serves its English copy rather than a partial mix.
 */
final class DemoHelpCenter
{
    /**
     * @return list<array{name: string, name_ar: string|null, slug: string, description: string, icon: string, featured: bool}>
     */
    public static function categories(): array
    {
        return [
            [
                'name' => 'Getting Started',
                'name_ar' => 'البداية',
                'slug' => 'getting-started',
                'description' => 'Create your workspace, secure your account and make your first sale.',
                'icon' => 'rocket',
                'featured' => true,
            ],
            [
                'name' => 'Products & Catalogue',
                'name_ar' => 'المنتجات والكتالوج',
                'slug' => 'products-and-catalogue',
                'description' => 'Build products with variants, images and SEO, and organise them into categories.',
                'icon' => 'box',
                'featured' => true,
            ],
            [
                'name' => 'Inventory',
                'name_ar' => 'المخزون',
                'slug' => 'inventory',
                'description' => 'Track stock across stores, receive and adjust it, and set low-stock alerts.',
                'icon' => 'layers',
                'featured' => false,
            ],
            [
                'name' => 'Orders & Customers',
                'name_ar' => 'الطلبات والعملاء',
                'slug' => 'orders-and-customers',
                'description' => 'Work the order pipeline and keep your customer list clean.',
                'icon' => 'cart',
                'featured' => true,
            ],
            [
                'name' => 'Storefront & Shipping',
                'name_ar' => 'المتجر والتوصيل',
                'slug' => 'storefront-and-shipping',
                'description' => 'Publish your public storefront and set up delivery zones.',
                'icon' => 'store',
                'featured' => false,
            ],
            [
                'name' => 'Payments',
                'name_ar' => 'المدفوعات',
                'slug' => 'payments',
                'description' => 'Cash on delivery, bank transfer and connected online payment providers.',
                'icon' => 'credit-card',
                'featured' => false,
            ],
            [
                'name' => 'Automations & Notifications',
                'name_ar' => 'الأتمتة والإشعارات',
                'slug' => 'automations-and-notifications',
                'description' => 'Let rules act on events, and keep your team and customers informed.',
                'icon' => 'workflow',
                'featured' => false,
            ],
            [
                'name' => 'WhatsApp Conversations',
                'name_ar' => 'محادثات واتساب',
                'slug' => 'whatsapp-conversations',
                'description' => 'Connect a business number and run sales inside the conversation.',
                'icon' => 'message-circle',
                'featured' => false,
            ],
            [
                'name' => 'Translations & Localization',
                'name_ar' => 'الترجمات والتعريب',
                'slug' => 'translations-and-localization',
                'description' => 'Serve your catalogue in Arabic and other locales through the translation ledger.',
                'icon' => 'languages',
                'featured' => false,
            ],
            [
                'name' => 'Account & Team',
                'name_ar' => 'الحساب والفريق',
                'slug' => 'account-and-team',
                'description' => 'Two-factor security, team invitations and custom roles.',
                'icon' => 'shield',
                'featured' => false,
            ],
        ];
    }

    /**
     * @return list<array{
     *     title: string,
     *     title_ar: string|null,
     *     slug: string,
     *     category: string,
     *     excerpt: string,
     *     excerpt_ar: string|null,
     *     body: string,
     *     body_ar: string|null,
     *     visibility: string,
     *     featured: bool,
     *     published: bool,
     *     tags: list<string>
     * }>
     */
    public static function articles(): array
    {
        return [
            [
                'title' => 'Create your workspace',
                'title_ar' => 'أنشئ مساحة عملك',
                'slug' => 'create-your-workspace',
                'category' => 'getting-started',
                'excerpt' => 'From a bare login to a working workspace with your first store — one atomic, retry-safe step.',
                'excerpt_ar' => 'من مجرد حساب إلى مساحة عمل كاملة مع أول متجر لك — خطوة واحدة آمنة وقابلة لإعادة المحاولة.',
                'visibility' => 'public',
                'featured' => true,
                'published' => true,
                'tags' => ['getting-started'],
                'body' => <<<'HTML'
                <p>A workspace — a <strong>tenant</strong> in Dukavo — holds your team, your stores and everything you sell. You create it the first time you sign in: an account with no tenant membership is taken straight to onboarding.</p>
                <h2>What onboarding asks for</h2>
                <ul>
                <li>The workspace name and its slug — the public URL segment your storefront lives under.</li>
                <li>Your country, city, currency, timezone and locale.</li>
                <li>The name of your <strong>first store</strong> inside the workspace.</li>
                </ul>
                <h2>What happens when you submit</h2>
                <p>One idempotent call provisions everything atomically: the tenant itself, your owner membership, the seeded system roles (<code>merchant</code> and <code>staff</code>), and your first store. A retried submission cannot create a half-built workspace or a duplicate one.</p>
                <p>On your first login you are shown the preferences confirmation once: review the detected country, city, timezone, currency and locale — each with a badge showing where it came from — then confirm or skip. It does not return.</p>
                <h2>After onboarding</h2>
                <p>You hold the tenant-scoped <code>merchant</code> role, which carries every tenant permission. Your next stop is usually <a href="/help/articles/create-a-product">creating your first product</a>.</p>
                HTML,
                'body_ar' => <<<'HTML'
                <p>مساحة العمل — أو ما يسمى في دوكافو «المستأجر» (tenant) — تضم فريقك ومتاجرك وكل ما تبيعه. تنشئها عند أول تسجيل دخول: أي حساب غير مرتبط بمساحة عمل ينتقل مباشرة إلى شاشة الإعداد.</p>
                <h2>ما يطلبه الإعداد</h2>
                <ul>
                <li>اسم مساحة العمل ومعرّفها (slug) — وهو الجزء الذي يظهر في الرابط العام لمتجرك.</li>
                <li>بلدك ومدينتك وعملتك ومنطقتك الزمنية ولغتك المفضلة.</li>
                <li>اسم <strong>أول متجر</strong> داخل مساحة العمل.</li>
                </ul>
                <h2>ما يحدث عند الإرسال</h2>
                <p>نداء واحد آمن ومتكرر ينشئ كل شيء دفعة واحدة: مساحة العمل، وعضويتك كمالك، والأدوار النظامية المهيأة (<code>merchant</code> و <code>staff</code>)، وأول متجر لك. إعادة إرسال الطلب لا يمكن أن تنشئ مساحة ناقصة أو مكررة.</p>
                <p>وعند أول تسجيل دخول تظهر لك صفحة تأكيد التفضيلات مرة واحدة: راجع البلد والمدينة والمنطقة الزمنية والعملة واللغة المكتشفة — مع بيان مصدر كل قيمة — ثم أكّد أو تجاوز.</p>
                <h2>بعد الإعداد</h2>
                <p>تحمل دور <code>merchant</code> داخل مساحة العمل، ويشمل كل الصلاحيات. خطوتك التالية عادة هي <a href="/help/articles/create-a-product">إنشاء أول منتج</a>.</p>
                HTML,
            ],
            [
                'title' => 'Log in and secure your account',
                'title_ar' => 'سجّل الدخول وأمّن حسابك',
                'slug' => 'log-in-and-secure-your-account',
                'category' => 'getting-started',
                'excerpt' => 'Sign-in with optional two-factor authentication, and why you should enable it before anything else.',
                'excerpt_ar' => 'تسجيل الدخول مع المصادقة الثنائية الاختيارية، ولماذا ينبغي تفعيلها قبل أي شيء آخر.',
                'visibility' => 'public',
                'featured' => true,
                'published' => true,
                'tags' => ['getting-started', 'security'],
                'body' => <<<'HTML'
                <p>Sign in at <code>/login</code> with your email and password, with an optional "remember me". You land on the first screen you have permission for — the dashboard, or orders, or conversations — in preference order.</p>
                <h2>Two-factor authentication</h2>
                <p>If two-factor authentication is enabled on the account, the second step asks for a TOTP code from your authenticator app, or one of your recovery codes.</p>
                <ol>
                <li>Open the sidebar avatar → <strong>Settings → Security</strong>.</li>
                <li>Choose <strong>Enable two-factor</strong> and scan the QR code with an authenticator app.</li>
                <li>Confirm with a code, then <strong>save the recovery codes</strong> somewhere safe.</li>
                </ol>
                <p>Recovery codes are shown once; regenerating them invalidates the old set.</p>
                <h2>Honest failure states</h2>
                <p>Login is throttled, and so is the two-factor challenge. Suspended accounts and suspended workspaces are refused with an honest status page — never a silent failure or a loop back to the form. Email verification runs through the signed link mailed to your address.</p>
                HTML,
                'body_ar' => <<<'HTML'
                <p>سجّل الدخول من <code>/login</code> ببريدك الإلكتروني وكلمة المرور، مع خيار «تذكرني». تصل إلى أول شاشة تملك صلاحية الوصول إليها — لوحة المعلومات أو الطلبات أو المحادثات — بحسب ترتيب الأفضلية.</p>
                <h2>المصادقة الثنائية</h2>
                <p>إن كانت مفعّلة على الحساب، فستطلب الخطوة الثانية رمز تحقق من تطبيق المصادقة أو أحد رموز الاستعادة.</p>
                <ol>
                <li>افتح صورة الملف الجانبية → <strong>الإعدادات → الأمان</strong>.</li>
                <li>اختر <strong>تفعيل المصادقة الثنائية</strong> وامسح رمز الاستجابة بتطبيق المصادقة.</li>
                <li>أكّد برمز، ثم <strong>احفظ رموز الاستعادة</strong> في مكان آمن.</li>
                </ol>
                <p>تُعرض رموز الاستعادة مرة واحدة فقط، وإعادة توليدها تُبطل المجموعة القديمة.</p>
                <h2>حالات فشل صريحة</h2>
                <p>تسجيل الدخول محدود المعدل، وكذلك خطوة المصادقة الثنائية. الحسابات الموقوفة ومساحات العمل الموقوفة تُرفض بصفحة حالة صريحة — لا فشل صامت ولا إعادة توجيه دائرية. يتم التحقق من البريد الإلكتروني عبر رابط موقّع يصل بالبريد.</p>
                HTML,
            ],
            [
                'title' => 'Build your first sale',
                'title_ar' => 'أكمل أول عملية بيع',
                'slug' => 'build-your-first-sale',
                'category' => 'getting-started',
                'excerpt' => 'The five-step path from an empty workspace to a customer placing an order.',
                'excerpt_ar' => 'المسار من خمس خطوات: من مساحة عمل فارغة إلى عميل يضع طلبًا.',
                'visibility' => 'public',
                'featured' => true,
                'published' => true,
                'tags' => ['getting-started'],
                'body' => <<<'HTML'
                <p>A first sale needs five things in place. Each links to the guide that covers it in depth.</p>
                <ol>
                <li><strong>Create a product with a variant</strong> — pricing and stock live on variants, never on the bare product. See <a href="/help/articles/create-a-product">Create a product</a>.</li>
                <li><strong>Stock it</strong> — receive stock for the variant so there is something to sell. See <a href="/help/articles/track-and-adjust-stock">Track and adjust stock</a>.</li>
                <li><strong>Set a delivery zone</strong> — so checkout can offer a shipping option with a fee and an estimate. See <a href="/help/articles/set-up-delivery-zones">Set up delivery zones</a>.</li>
                <li><strong>Publish your storefront</strong> — an active, published store is reachable at <code>/shop/{tenantSlug}/{storeSlug}</code>. See <a href="/help/articles/publish-your-storefront">Publish your storefront</a>.</li>
                <li><strong>Connect WhatsApp</strong> — so customers who prefer to chat can order in the conversation. See <a href="/help/articles/connect-whatsapp">Connect a WhatsApp business account</a>.</li>
                </ol>
                <p>When a customer orders, the order appears in <code>/orders</code> in the <code>pending</code> state; each guide below the getting-started set covers what to do from there.</p>
                HTML,
                'body_ar' => <<<'HTML'
                <p>تتطلب أول عملية بيع تهيئة خمسة أمور، ولكل منها دليل مستقل يشرحها بالتفصيل.</p>
                <ol>
                <li><strong>أنشئ منتجًا مع نمط</strong> — السعر والمخزون يرتبطان بالنمط لا بالمنتج المجرد. راجع <a href="/help/articles/create-a-product">إنشاء منتج</a>.</li>
                <li><strong>ورّد المخزون</strong> — أضف كمية للنمط حتى يكون هناك ما يُباع. راجع <a href="/help/articles/track-and-adjust-stock">متابعة المخزون وتعديله</a>.</li>
                <li><strong>حدّد منطقة توصيل</strong> — ليعرض الدفع خيار توصيل برسوم ومدة تقديرية. راجع <a href="/help/articles/set-up-delivery-zones">إعداد مناطق التوصيل</a>.</li>
                <li><strong>انشر متجرك</strong> — المتجر الفعال والمنشور متاح على الرابط <code>/shop/{tenantSlug}/{storeSlug}</code>. راجع <a href="/help/articles/publish-your-storefront">نشر المتجر</a>.</li>
                <li><strong>اربط واتساب</strong> — ليمكن للعملاء الذين يفضلون المحادثة الطلب داخل المحادثة. راجع <a href="/help/articles/connect-whatsapp">ربط حساب واتساب للأعمال</a>.</li>
                </ol>
                <p>عند وصول الطلب يظهر في <code>/orders</code> بحالة <code>pending</code>، وتفسر الأدلة التالية ما يجب فعله بعد ذلك.</p>
                HTML,
            ],
            [
                'title' => 'Create a product',
                'title_ar' => 'أنشئ منتجًا',
                'slug' => 'create-a-product',
                'category' => 'products-and-catalogue',
                'excerpt' => 'Variants carry the SKU, price and stock — the product is the container they live in.',
                'excerpt_ar' => 'النمط هو ما يحمل الرمز والسعر والمخزون — والمنتج هو الوعاء الذي يضمها.',
                'visibility' => 'public',
                'featured' => true,
                'published' => true,
                'tags' => ['products'],
                'body' => <<<'HTML'
                <p>Open <code>/products</code> and choose <strong>New product</strong>. Fill in the identity — name, description, slug and SEO fields, and the category the product belongs to.</p>
                <h2>Variants are required</h2>
                <p>A product always has at least one variant; the first is the default. SKU, price and stock are set <strong>per variant</strong>, never on the bare product. A t-shirt in two colours is one product with two variants.</p>
                <h2>Images</h2>
                <p>Upload images, reorder them to control the gallery order, and set alt text and captions for each. Images are translatable entities too.</p>
                <h2>Save or publish</h2>
                <p>Save the product as a draft or publish it as active. The lifecycle is <code>draft → active ⇄ archived</code>. Deletion is a soft delete, and an archived product frees its slug only after deletion.</p>
                <h2>Shared data versus localized content</h2>
                <p>SKU, pricing, stock, variant structure and images exist as a <strong>single copy</strong> tenant-wide — edit once, it applies everywhere. Names, descriptions, slugs and SEO exist per locale through the <a href="/help/articles/translate-your-content">translation ledger</a>. Editing a source field marks existing approved translations stale, so regenerate and re-approve after meaningful changes.</p>
                HTML,
                'body_ar' => <<<'HTML'
                <p>افتح <code>/products</code> واختر <strong>منتج جديد</strong>. املأ بيانات المنتج: الاسم والوصف والمعرّف وحقول تحسين محركات البحث والتصنيف الذي ينتمي إليه.</p>
                <h2>الأنماط إلزامية</h2>
                <p>لكل منتج نمط واحد على الأقل، والأول هو النمط الافتراضي. يُحدد الرمز والسعر والمخزون <strong>لكل نمط</strong> وليس للمنتج المجرد. قميص بلونين هو منتج واحد بنمطين.</p>
                <h2>الصور</h2>
                <p>ارفع الصور وأعد ترتيبها للتحكم في ترتيب المعرض، وحدّد نصًا بديلًا وتعليقًا لكل صورة. الصور كذلك كيانات قابلة للترجمة.</p>
                <h2>حفظ أو نشر</h2>
                <p>احفظ المنتج كمسودة أو انشره كفعّال. دورة الحياة هي <code>draft → active ⇄ archived</code>. الحذف حذف منطقي، ولا يحرر المنتج المؤرشف معرّفه إلا بعد الحذف.</p>
                <h2>البيانات المشتركة مقابل المحتوى المترجم</h2>
                <p>الرمز والسعر والمخزون وبنية الأنماط والصور لها <strong>نسخة واحدة</strong> على مستوى مساحة العمل. أما الأسماء والأوصاف والمعرّفات وحقول SEO فهي لكل لغة عبر <a href="/help/articles/translate-your-content">سجل الترجمات</a>. تعديل الحقل الأصلي يعلّم الترجمات المعتمدة بأنها بحاجة لتحديث، فأعد توليدها واعتمدها بعد أي تغيير جوهري.</p>
                HTML,
            ],
            [
                'title' => 'Organise your catalogue with categories',
                'title_ar' => null,
                'slug' => 'organise-your-catalogue-with-categories',
                'category' => 'products-and-catalogue',
                'excerpt' => 'Group products into a reorderable tree that drives storefront browsing.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['products'],
                'body' => <<<'HTML'
                <p>Categories give your storefront its browsing structure. <code>/categories</code> shows the tree: create root categories or nest children under them.</p>
                <ul>
                <li>Each category has a name, a description and a slug, with live availability checking while you type.</li>
                <li><strong>Reorder</strong> siblings to control the order the storefront lists them in.</li>
                <li>Assign products to categories from the product form.</li>
                </ul>
                <h2>Localizing categories</h2>
                <p>Categories are bilingual-capable: Arabic mirrors the legacy <code>name_ar</code> columns, and further locales flow through the <a href="/help/articles/translate-your-content">translation ledger</a> — generate, review, approve.</p>
                <h2>Deleting</h2>
                <p>Deleting a category removes it from the tree and detaches its products — the products themselves are untouched. The public tree lists active categories only, so hiding one from the storefront without deleting it is simply a matter of its visibility.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Track and adjust stock',
                'title_ar' => null,
                'slug' => 'track-and-adjust-stock',
                'category' => 'inventory',
                'excerpt' => 'Receive, adjust with a reason, transfer between stores — and how orders reserve stock.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['inventory'],
                'body' => <<<'HTML'
                <p>Stock in Dukavo is counted per variant, per store. <code>/inventory</code> shows where every SKU stands.</p>
                <h2>Everyday moves</h2>
                <ul>
                <li><strong>Receive stock</strong> when a delivery arrives.</li>
                <li><strong>Adjust stock</strong> to correct a count — every adjustment records a reason, so the history explains itself.</li>
                <li><strong>Transfer</strong> units between stores when one location is short.</li>
                <li><strong>Low-stock thresholds</strong> trigger restock reminders and drive inventory intelligence.</li>
                </ul>
                <h2>How orders move stock</h2>
                <p>A new order <strong>reserves</strong> the stock for its lines. Moving an order to a consuming status — confirmed and beyond — consumes the reservation; cancelling a pending order releases it. You never adjust stock by hand for an order; the pipeline does it.</p>
                <blockquote><p>Because the transition matrix is enforced server-side, stock can never be consumed twice or leaked by an invalid move.</p></blockquote>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Manage orders',
                'title_ar' => null,
                'slug' => 'manage-orders',
                'category' => 'orders-and-customers',
                'excerpt' => 'The order pipeline, its allowed transitions, and how payment status runs beside it.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => true,
                'published' => true,
                'tags' => ['orders'],
                'body' => <<<'HTML'
                <p>Orders live at <code>/orders</code>, searchable and filterable by status and store. Each is addressed by its human order number, in the <code>ORD-000123</code> style.</p>
                <h2>The pipeline</h2>
                <p><code>pending → confirmed → processing → shipped → delivered</code>, with <code>cancelled</code> reachable from pending, confirmed and processing. Delivered and cancelled are final.</p>
                <p>The status control only offers <strong>allowed</strong> transitions — the server computes them and enforces them on submit regardless of what a client sends. Transitioning to a consuming status (confirmed and beyond) consumes the reserved stock; cancelling a pending order releases it.</p>
                <h2>Creating an order manually</h2>
                <p>Phone orders and WhatsApp orders can be entered by hand: pick the customer (creating one on the fly if needed), pick the store, then add product variants with quantities. The server prices every line — the merchant never types prices.</p>
                <h2>Payment status</h2>
                <p>Payment runs in parallel to the order state: <code>unpaid</code>, <code>partially_paid</code>, <code>paid</code>, <code>refunded</code>. You can mark payment collected where the flow allows it. Online payment refunds are issued from the payments module, not from the order screen.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Publish your storefront',
                'title_ar' => null,
                'slug' => 'publish-your-storefront',
                'category' => 'storefront-and-shipping',
                'excerpt' => 'Identity, branding, policies and the publish switch that makes the store public.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['storefront'],
                'body' => <<<'HTML'
                <p>Open <code>/storefront</code> and select the store. Fill in the identity fields — name, description and contact details — and the branding.</p>
                <h2>The publish switch</h2>
                <p>The moment a store is both active and published, its public shop becomes reachable at <code>/shop/{tenantSlug}/{storeSlug}</code>. An inactive or unpublished store returns a <strong>404</strong> — never a half-dressed page. Verify in a private browser window, since the storefront is fully public.</p>
                <h2>Policies and SEO</h2>
                <p>Refund and shipping policy text and SEO fields are part of the settings payload, and localized variants flow through the <a href="/help/articles/translate-your-content">translation ledger</a>.</p>
                <h2>Storefront locales</h2>
                <p><code>enabled_locales</code> on the store decides which languages the storefront can serve. Each non-source locale needs <strong>approved</strong> translations to actually display localized text; otherwise the documented fallback rules apply.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Set up delivery zones',
                'title_ar' => null,
                'slug' => 'set-up-delivery-zones',
                'category' => 'storefront-and-shipping',
                'excerpt' => 'Coverage by city, a fee, an estimate — what checkout does with a zone.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['shipping'],
                'body' => <<<'HTML'
                <p>A delivery zone describes where you deliver, what it costs and how long it takes. Create one per coverage area: name, the cities it covers, the delivery fee and the estimated delivery time.</p>
                <h2>What happens at checkout</h2>
                <p>At checkout the customer's address is matched against your zones; the matching zone's fee and estimate are applied to the order. A store with no zone covering the address cannot offer delivery for it.</p>
                <h2>Localizing zone copy</h2>
                <p>Zone names and descriptions are translatable through the <a href="/help/articles/translate-your-content">translation ledger</a> — generate, review, approve, and only approved text publishes.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Accept payments',
                'title_ar' => null,
                'slug' => 'accept-payments',
                'category' => 'payments',
                'excerpt' => 'Cash on delivery, bank transfer, and connecting an online payment provider.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['payments'],
                'body' => <<<'HTML'
                <p>Dukavo supports three payment routes, and they can coexist on the same store.</p>
                <h2>Cash on delivery</h2>
                <p>The simplest method: enable it and orders can be placed with payment collected at the door. Mark payment collected when you move the order forward.</p>
                <h2>Bank transfer</h2>
                <p>For manual verification: the customer is shown your transfer instructions at checkout and you record the payment once it lands.</p>
                <h2>Online payment providers</h2>
                <p>The platform decides which providers are offered in each market. Connecting one to your store follows the platform's flow; once connected, checkout offers hosted online payment and the order's payment status tracks the provider's webhooks automatically. Refunds for online payments are issued from the payments module.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Automate your work',
                'title_ar' => null,
                'slug' => 'automate-your-work',
                'category' => 'automations-and-notifications',
                'excerpt' => 'Rules that react to events: triggers, actions, human tasks and the template library.',
                'excerpt_ar' => null,
                'visibility' => 'merchants',
                'featured' => false,
                'published' => true,
                'tags' => ['automations'],
                'body' => <<<'HTML'
                <p>An automation rule is a trigger plus an action: when a customer is created, send them a welcome; when stock runs low, notify the manager. There are fourteen triggers and five actions the engine supports.</p>
                <h2>Creating a rule</h2>
                <p>Open the automations section and create a rule: pick the trigger, configure its conditions, choose the action, and activate it. Rules run on the event bus — the <strong>runs</strong> view shows every execution, what it did and what it skipped.</p>
                <h2>Human tasks</h2>
                <p>Some actions create a human task instead of acting immediately — for example a drafted follow-up message that a team member approves before it goes out. Tasks appear in the tasks view until they are worked.</p>
                <h2>The template library</h2>
                <p>Instead of building a rule from scratch, install one from the template library: platform-prepared rules that land in your workspace ready to activate.</p>
                <h2>AI safety</h2>
                <p>When a rule's action involves AI, it runs inside the governed AI action pipeline — it proposes, a human approves, only then does it execute. Automation never bypasses that gate.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Connect a WhatsApp business account',
                'title_ar' => null,
                'slug' => 'connect-whatsapp',
                'category' => 'whatsapp-conversations',
                'excerpt' => 'From a pending number to a connected inbox your team can work together.',
                'excerpt_ar' => null,
                'visibility' => 'merchants',
                'featured' => false,
                'published' => true,
                'tags' => ['whatsapp'],
                'body' => <<<'HTML'
                <p>Open <code>/whatsapp</code> and choose <strong>Add account</strong>: enter the number and the provider fields. The account starts <code>pending</code>.</p>
                <h2>Verify</h2>
                <p>Verification checks the number with Meta using the platform's WhatsApp configuration; success moves the account to <code>connected</code>. The page also shows the platform configuration status — sending is only possible when that configuration is complete.</p>
                <h2>Working the inbox</h2>
                <p><code>/conversations</code> lists your threads, filterable by status, assignment and read state. Inside a thread you can:</p>
                <ul>
                <li>reply — outbound messages are idempotent, so a double-submit is safe;</li>
                <li>retry a failed outbound message;</li>
                <li>write internal notes — team-only annotations;</li>
                <li>send from <strong>approved templates only</strong>, per WhatsApp policy;</li>
                <li>assign the conversation to a Store Member — which fires the <code>conversation.assigned</code> automation trigger.</li>
                </ul>
                <h2>Voice messages</h2>
                <p>A voice note can be transcribed, and the extracted order proposal — items and quantities — can be applied as a governed AI action that creates the order only after approval.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Translate your content',
                'title_ar' => null,
                'slug' => 'translate-your-content',
                'category' => 'translations-and-localization',
                'excerpt' => 'The ledger: generate, review, approve — only approved text ever publishes.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['translations'],
                'body' => <<<'HTML'
                <p>Everything a customer can read can exist in more than one language: products, categories, storefront copy, delivery zones and more. All of it flows through the same <strong>translation ledger</strong>.</p>
                <h2>The workflow</h2>
                <ol>
                <li><strong>Generate</strong> a draft translation — with AI assistance or written by hand.</li>
                <li><strong>Review</strong> it against the source, editing anything that reads wrong.</li>
                <li><strong>Approve</strong> it. Only approved text is ever served.</li>
                </ol>
                <p>Nothing publishes automatically: an unapproved draft can never leak to a shopper.</p>
                <h2>Staying current</h2>
                <p>When a source field changes, its approved translations are marked <strong>stale</strong> so you know exactly which ones need regenerating. Arabic on entities with legacy <code>*_ar</code> columns is mirrored into those columns on approval; additional locales are served straight from the ledger.</p>
                <h2>Enabling a locale for shoppers</h2>
                <p>A locale only appears on the storefront when the store lists it in its enabled locales <em>and</em> has approved translations for it — otherwise the documented fallback serves the store's default language rather than a half-translated page.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Invite your team and assign roles',
                'title_ar' => null,
                'slug' => 'invite-your-team-and-assign-roles',
                'category' => 'account-and-team',
                'excerpt' => 'Invitations, the seeded system roles, custom roles and the audit trail.',
                'excerpt_ar' => null,
                'visibility' => 'public',
                'featured' => false,
                'published' => true,
                'tags' => ['team', 'security'],
                'body' => <<<'HTML'
                <p>Open <code>/users</code> and choose <strong>Invite user</strong>: name, email, initial role. The account starts <code>invited</code>; email verification and password setup run through the standard auth mail. When they sign in they appear with their role's permissions.</p>
                <h2>Roles</h2>
                <p>Two system roles are seeded and protected: <code>merchant</code> (every tenant permission) and <code>staff</code> (a read-mostly set). For anything else, create a <strong>custom role</strong> at <code>/roles</code>: the grouped permission editor lists every tenant-relevant permission by module, and the grant set is replaced atomically when you save.</p>
                <p>Assign or change a role from the user row; the person's effective permissions recompute on their next session read.</p>
                <h2>The audit trail</h2>
                <p><code>/audit</code> shows your workspace's rows only — actor, action, old and new values — so who changed what is always reconstructible.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'Understanding the dashboard',
                'title_ar' => null,
                'slug' => 'understanding-the-dashboard',
                'category' => 'getting-started',
                'excerpt' => 'What the numbers on your first screen mean and where they come from.',
                'excerpt_ar' => null,
                'visibility' => 'authenticated',
                'featured' => false,
                'published' => true,
                'tags' => ['getting-started', 'analytics'],
                'body' => <<<'HTML'
                <p>The dashboard is the merchant landing screen: today's revenue, orders, stock health and recent activity at a glance, scoped to one store or the whole workspace.</p>
                <h2>What you see</h2>
                <ul>
                <li>KPI cards — revenue, orders and headline counts for the selected scope.</li>
                <li>A revenue trend over the selected range, and best-selling products.</li>
                <li>Stock health — low stock at or below thresholds, and dead stock from inventory intelligence.</li>
                <li>Recent orders with status, and the latest audit feed entries.</li>
                <li>An AI insights panel when AI use is permitted and available — it states availability honestly rather than guessing.</li>
                </ul>
                <h2>Scope and range</h2>
                <p>Pick a store at the top and every figure recomputes server-side; pick a range where offered and the server aggregates per range. Every list drills into its module — an order opens its detail, a product opens the product.</p>
                <p>All figures are server-side aggregates of real rows. Panels you lack permission for are hidden, and their APIs refuse the wider tenant — the sidebar and the server agree on what you may see.</p>
                HTML,
                'body_ar' => null,
            ],
            [
                'title' => 'The AI assistant and governed actions',
                'title_ar' => null,
                'slug' => 'the-ai-assistant-and-governed-actions',
                'category' => 'automations-and-notifications',
                'excerpt' => 'AI drafts, humans decide: reply drafts, copilots and insights — and the approval gate everything mutating passes through.',
                'excerpt_ar' => null,
                'visibility' => 'merchants',
                'featured' => false,
                'published' => false,
                'tags' => ['ai'],
                'body' => <<<'HTML'
                <p>AI features in Dukavo follow one rule: AI does the drafting and the thinking, people keep the decisions. Reply drafts, copilots, insights and summaries never send themselves; anything that would mutate data passes through the governed action pipeline.</p>
                <h2>Where AI helps</h2>
                <ul>
                <li><strong>Reply draft</strong> — in a conversation thread, generate a suggested reply, edit it, then send it yourself.</li>
                <li><strong>Sales copilot</strong> — a panel suggesting what to offer the customer next.</li>
                <li><strong>Insights</strong> — a dashboard panel of workspace insights.</li>
                <li><strong>Summaries</strong> — conversation and customer summaries where you work with them.</li>
                <li><strong>Bundle explanations</strong> — why a recommended bundle fits.</li>
                </ul>
                <h2>The approval gate</h2>
                <p>A proposed action lands in the AI approvals queue at <code>/ai/approvals</code>. A person with the approval permission accepts or rejects it; only an accepted action executes. Stale approvals expire on their own rather than waiting forever.</p>
                <h2>Permissions</h2>
                <p>Using AI features needs <code>ai.use</code>; approving actions needs <code>ai.approve</code>. Both are part of the seeded <code>merchant</code> and <code>staff</code> roles. When AI is not configured for your workspace, the panels say so honestly instead of guessing.</p>
                HTML,
                'body_ar' => null,
            ],
        ];
    }
}
