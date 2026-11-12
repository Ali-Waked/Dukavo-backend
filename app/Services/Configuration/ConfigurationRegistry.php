<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\ConfigurationCategory;
use App\Enums\ConfigurationSourcePolicy;
use App\Enums\ConfigurationType;

/**
 * The closed catalogue of platform configuration.
 *
 * If a key is not here, it cannot be written, read through the settings API or
 * applied to the framework. That is the deliberate replacement for "store any
 * config path in the database": there is no dynamic class loading, no arbitrary
 * `config()` path and no way for a compromised admin session to repoint the
 * application at an attacker-controlled endpoint outside the allow-listed
 * options.
 *
 * The registry also documents the boundary between editable integration
 * settings and environment-only infrastructure. The latter is exposed so an
 * operator can see *why* a value cannot be changed here, never so it can be
 * changed.
 */
final class ConfigurationRegistry
{
    /**
     * @var array<string, ConfigurationDefinition>|null
     */
    private ?array $definitions = null;

    /**
     * @return array<string, ConfigurationDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ([
            ...$this->general(),
            ...$this->localization(),
            ...$this->email(),
            ...$this->storage(),
            ...$this->security(),
            ...$this->maintenance(),
            ...$this->ai(),
            ...$this->whatsapp(),
            ...$this->features(),
            ...$this->infrastructure(),
        ] as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return $this->definitions = $definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ConfigurationDefinition
    {
        $definition = $this->all()[$key] ?? null;

        if (! $definition instanceof ConfigurationDefinition) {
            throw new \InvalidArgumentException(sprintf('Unknown platform configuration key [%s].', $key));
        }

        return $definition;
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    public function forCategory(ConfigurationCategory $category): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ConfigurationDefinition $definition): bool => $definition->category === $category,
        ));
    }

    /**
     * @return list<ConfigurationCategory>
     */
    public function categories(): array
    {
        return ConfigurationCategory::cases();
    }

    /**
     * Validation rules keyed by `values.<setting key>`.
     *
     * Every field is optional because the dashboard saves a section
     * partially, but an unknown key is rejected rather than ignored: silently
     * dropping a typo would make an operator believe a change was saved.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(ConfigurationCategory $category, bool $mayManageSecrets): array
    {
        $rules = ['values' => ['required', 'array']];

        $rules['values'][] = function (string $attribute, mixed $value, \Closure $fail) use ($category): void {
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $field) {
                $key = is_string($field) && str_contains($field, '.')
                    ? $field
                    : $category->value.'.'.$field;

                if (! $this->has($key) || $this->get($key)->category !== $category) {
                    $fail(sprintf('The setting [%s] is not part of the %s category.', $field, $category->value));
                }
            }
        };

        foreach ($this->forCategory($category) as $definition) {
            $path = 'values.'.$this->field($definition);

            if (! $definition->editable()) {
                $rules[$path] = ['prohibited'];

                continue;
            }

            $fieldRules = $definition->validationRules();

            if ($definition->isSecret() && ! $mayManageSecrets) {
                $fieldRules = ['prohibited'];
            }

            $rules[$path] = ['sometimes', 'nullable', ...$fieldRules];
        }

        return $rules;
    }

    /**
     * The form field a category payload uses: the key without its category
     * prefix. Dotted keys cannot be used as Laravel validation paths because a
     * dot means nesting, so the HTTP contract deliberately stays flat.
     */
    public function field(ConfigurationDefinition $definition): string
    {
        $prefix = $definition->category->value.'.';

        return str_starts_with($definition->key, $prefix)
            ? substr($definition->key, strlen($prefix))
            : $definition->key;
    }

    /**
     * @return array<string, string>
     */
    public function knownKeysByCategory(ConfigurationCategory $category): array
    {
        $keys = [];

        foreach ($this->forCategory($category) as $definition) {
            $keys[$definition->key] = $definition->key;
        }

        return $keys;
    }

    /* ------------------------------------------------------------------ groups */

    /**
     * @return list<ConfigurationDefinition>
     */
    private function general(): array
    {
        return [
            $this->def('general.platform_name', ConfigurationCategory::General, ConfigurationType::String,
                'Platform name', 'اسم المنصة',
                'Shown in the admin chrome and used as the product name in platform copy.',
                'يظهر في واجهة الإدارة ويُستخدم كاسم المنتج في نصوص المنصة.',
                default: (string) config('dukavo.name', 'Dukavo'), envKey: 'DUKAVO_NAME', configPath: 'dukavo.platform.name', maxLength: 120, group: 'identity'),
            $this->def('general.platform_description', ConfigurationCategory::General, ConfigurationType::Text,
                'Platform description', 'وصف المنصة',
                'A short public description of what Dukavo is.',
                'وصف عام قصير لما تقدمه منصة دوكافو.',
                default: (string) config('dukavo.platform.description', ''), envKey: 'DUKAVO_DESCRIPTION', configPath: 'dukavo.platform.description', maxLength: 500, group: 'identity'),
            $this->def('general.website_url', ConfigurationCategory::General, ConfigurationType::Url,
                'Website URL', 'رابط الموقع',
                'The public marketing site address.',
                'عنوان الموقع التسويقي العام.',
                default: (string) config('dukavo.platform.website_url', ''), envKey: 'DUKAVO_WEBSITE_URL', configPath: 'dukavo.platform.website_url', group: 'identity'),
            $this->def('general.support_email', ConfigurationCategory::General, ConfigurationType::Email,
                'Support email', 'بريد الدعم',
                'Where customers and merchants are told to ask for help.',
                'العنوان الذي يُطلب من العملاء والتجار التواصل معه للمساعدة.',
                default: (string) config('dukavo.platform.support_email', ''), envKey: 'DUKAVO_SUPPORT_EMAIL', configPath: 'dukavo.platform.support_email', group: 'contact'),
            $this->def('general.contact_email', ConfigurationCategory::General, ConfigurationType::Email,
                'Official email', 'البريد الرسمي',
                'The official platform address shown on legal and contact surfaces.',
                'البريد الرسمي للمنصة في الصفحات القانونية وصفحات التواصل.',
                default: (string) config('dukavo.platform.contact_email', ''), envKey: 'DUKAVO_CONTACT_EMAIL', configPath: 'dukavo.platform.contact_email', group: 'contact'),
            $this->def('general.support_phone', ConfigurationCategory::General, ConfigurationType::String,
                'Support phone', 'هاتف الدعم',
                'An optional public support number in international format.',
                'رقم دعم عام اختياري بالصيغة الدولية.',
                default: (string) config('dukavo.platform.support_phone', ''), envKey: 'DUKAVO_SUPPORT_PHONE', configPath: 'dukavo.platform.support_phone', maxLength: 40, group: 'contact'),
            $this->def('general.logo_url', ConfigurationCategory::General, ConfigurationType::Url,
                'Logo URL', 'رابط الشعار',
                'A publicly reachable logo used in email and public surfaces.',
                'رابط شعار متاح للعامة يُستخدم في البريد والواجهات العامة.',
                default: (string) config('dukavo.platform.logo_url', ''), envKey: 'DUKAVO_LOGO_URL', configPath: 'dukavo.platform.logo_url', group: 'brand'),
            $this->def('general.favicon_url', ConfigurationCategory::General, ConfigurationType::Url,
                'Favicon URL', 'رابط أيقونة الموقع',
                'A publicly reachable favicon.',
                'رابط أيقونة موقع متاح للعامة.',
                default: (string) config('dukavo.platform.favicon_url', ''), envKey: 'DUKAVO_FAVICON_URL', configPath: 'dukavo.platform.favicon_url', group: 'brand'),
            $this->def('general.social_x_url', ConfigurationCategory::General, ConfigurationType::Url,
                'X profile', 'حساب إكس',
                'Public X (Twitter) profile URL.',
                'رابط حساب إكس (تويتر) العام.',
                default: (string) config('dukavo.platform.social_x_url', ''), configPath: 'dukavo.platform.social_x_url', group: 'social'),
            $this->def('general.social_linkedin_url', ConfigurationCategory::General, ConfigurationType::Url,
                'LinkedIn page', 'صفحة لينكدإن',
                'Public LinkedIn page URL.',
                'رابط صفحة لينكدإن العامة.',
                default: (string) config('dukavo.platform.social_linkedin_url', ''), configPath: 'dukavo.platform.social_linkedin_url', group: 'social'),
            $this->def('general.social_instagram_url', ConfigurationCategory::General, ConfigurationType::Url,
                'Instagram profile', 'حساب إنستغرام',
                'Public Instagram profile URL.',
                'رابط حساب إنستغرام العام.',
                default: (string) config('dukavo.platform.social_instagram_url', ''), configPath: 'dukavo.platform.social_instagram_url', group: 'social'),
            $this->def('general.social_facebook_url', ConfigurationCategory::General, ConfigurationType::Url,
                'Facebook page', 'صفحة فيسبوك',
                'Public Facebook page URL.',
                'رابط صفحة فيسبوك العامة.',
                default: (string) config('dukavo.platform.social_facebook_url', ''), configPath: 'dukavo.platform.social_facebook_url', group: 'social'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function localization(): array
    {
        $locales = array_values(array_filter((array) config('dukavo.locales.supported', []), 'is_string'));
        $currencies = array_keys((array) config('dukavo.currencies.supported', []));
        $timezones = (array) require config_path('timezones.php');

        return [
            $this->def('localization.default_locale', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Default language', 'اللغة الافتراضية',
                'The fallback language for public surfaces when a request does not choose one. A user preference and a store language always win.',
                'لغة الاحتياط للواجهات العامة عندما لا يحدد الطلب لغة. تفضيل المستخدم ولغة المتجر لهما الأولوية دائمًا.',
                default: (string) config('dukavo.locales.default', 'en'), envKey: 'APP_LOCALE', configPath: 'app.locale', options: $locales, group: 'language'),
            $this->def('localization.supported_locales', ConfigurationCategory::Localization, ConfigurationType::MultiSelect,
                'Supported languages', 'اللغات المدعومة',
                'The languages the platform may serve. A store still enables its own subset.',
                'اللغات التي يمكن للمنصة تقديمها. يظل لكل متجر لغاته المفعّلة.',
                default: $locales, envKey: 'DUKAVO_SUPPORTED_LOCALES', configPath: 'dukavo.locales.supported', options: $locales, group: 'language'),
            $this->def('localization.default_timezone', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Default timezone', 'المنطقة الزمنية الافتراضية',
                'Used when a user or store has no timezone of its own.',
                'تُستخدم عندما لا يملك المستخدم أو المتجر منطقة زمنية خاصة.',
                default: (string) config('app.timezone', 'UTC'), envKey: 'APP_TIMEZONE', configPath: 'app.timezone', options: $timezones, group: 'region'),
            $this->def('localization.default_currency', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Default currency', 'العملة الافتراضية',
                'The display currency before a user preference or a store base currency applies.',
                'عملة العرض قبل تطبيق تفضيل المستخدم أو عملة المتجر الأساسية.',
                default: (string) config('dukavo.currencies.default', 'USD'), envKey: 'DUKAVO_DEFAULT_CURRENCY', configPath: 'dukavo.currencies.default', options: $currencies, group: 'region'),
            $this->def('localization.date_format', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Date format', 'تنسيق التاريخ',
                'The platform default date format. Individual surfaces may still follow the active locale.',
                'تنسيق التاريخ الافتراضي للمنصة. قد تتبع بعض الواجهات اللغة النشطة.',
                default: (string) config('dukavo.formats.date', 'Y-m-d'), envKey: 'DUKAVO_DATE_FORMAT', configPath: 'dukavo.formats.date', options: ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y'], group: 'format'),
            $this->def('localization.time_format', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Time format', 'تنسيق الوقت',
                '24-hour or 12-hour clock for the platform default.',
                'ساعة 24 أو 12 ساعة للتنسيق الافتراضي للمنصة.',
                default: (string) config('dukavo.formats.time', 'H:i'), envKey: 'DUKAVO_TIME_FORMAT', configPath: 'dukavo.formats.time', options: ['H:i', 'h:i A'], group: 'format'),
            $this->def('localization.week_starts_on', ConfigurationCategory::Localization, ConfigurationType::Select,
                'Week starts on', 'بداية الأسبوع',
                'The first day of the week in platform calendars.',
                'أول يوم في الأسبوع في تقويمات المنصة.',
                default: (string) config('dukavo.formats.week_starts_on', 1), configPath: 'dukavo.formats.week_starts_on', options: ['0', '1', '6'], group: 'format'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function email(): array
    {
        return [
            $this->def('email.mailer', ConfigurationCategory::Email, ConfigurationType::Select,
                'Mail driver', 'مشغل البريد',
                'How Dukavo sends email. SMTP uses the fields below; log and array are safe development drivers.',
                'كيف ترسل دوكافو البريد. يستخدم SMTP الحقول أدناه؛ log و array مشغلان آمنان للتطوير.',
                default: (string) config('mail.default', 'log'), envKey: 'MAIL_MAILER', configPath: 'mail.default', options: ['smtp', 'log', 'array', 'ses', 'postmark', 'resend'], group: 'transport'),
            $this->def('email.host', ConfigurationCategory::Email, ConfigurationType::String,
                'SMTP host', 'مضيف SMTP',
                'The SMTP server hostname.',
                'اسم مضيف خادم SMTP.',
                default: (string) config('mail.mailers.smtp.host', ''), envKey: 'MAIL_HOST', configPath: 'mail.mailers.smtp.host', maxLength: 255, group: 'transport'),
            $this->def('email.port', ConfigurationCategory::Email, ConfigurationType::Integer,
                'SMTP port', 'منفذ SMTP',
                'Usually 587 for STARTTLS, 465 for implicit TLS or 25 for a relay.',
                'عادةً 587 لـ STARTTLS أو 465 لـ TLS الصريح أو 25 للترحيل.',
                default: (int) config('mail.mailers.smtp.port', 587), envKey: 'MAIL_PORT', configPath: 'mail.mailers.smtp.port', min: 1, max: 65535, group: 'transport'),
            $this->def('email.username', ConfigurationCategory::Email, ConfigurationType::String,
                'SMTP username', 'اسم مستخدم SMTP',
                'The SMTP account name, if the server requires authentication.',
                'اسم حساب SMTP إذا كان الخادم يتطلب مصادقة.',
                default: (string) config('mail.mailers.smtp.username', ''), envKey: 'MAIL_USERNAME', configPath: 'mail.mailers.smtp.username', maxLength: 255, group: 'transport'),
            $this->def('email.password', ConfigurationCategory::Email, ConfigurationType::Secret,
                'SMTP password', 'كلمة مرور SMTP',
                'Stored encrypted and never returned. Enter a new value to replace it.',
                'تُخزن مشفرة ولا تُعاد أبدًا. أدخل قيمة جديدة لاستبدالها.',
                envKey: 'MAIL_PASSWORD', configPath: 'mail.mailers.smtp.password', group: 'transport'),
            $this->def('email.encryption', ConfigurationCategory::Email, ConfigurationType::Select,
                'Encryption', 'التشفير',
                'TLS for STARTTLS (usually port 587), SSL for implicit TLS (usually port 465), or none.',
                'TLS لـ STARTTLS (عادة المنفذ 587) أو SSL لـ TLS الصريح (عادة المنفذ 465) أو بدون.',
                default: 'tls', envKey: 'MAIL_SCHEME', configPath: 'mail.mailers.smtp.scheme', options: ['tls', 'ssl', 'none'], group: 'transport'),
            $this->def('email.from_address', ConfigurationCategory::Email, ConfigurationType::Email,
                'From address', 'عنوان المرسل',
                'The address every platform email is sent from.',
                'العنوان الذي تُرسل منه كل رسائل المنصة.',
                default: (string) config('mail.from.address', ''), envKey: 'MAIL_FROM_ADDRESS', configPath: 'mail.from.address', group: 'identity'),
            $this->def('email.from_name', ConfigurationCategory::Email, ConfigurationType::String,
                'From name', 'اسم المرسل',
                'The display name recipients see in their inbox.',
                'الاسم الذي يراه المستلمون في صندوق بريدهم.',
                default: (string) config('mail.from.name', (string) config('dukavo.name', 'Dukavo')), envKey: 'MAIL_FROM_NAME', configPath: 'mail.from.name', maxLength: 120, group: 'identity'),
            $this->def('email.reply_to', ConfigurationCategory::Email, ConfigurationType::Email,
                'Reply-to address', 'عنوان الرد',
                'Where replies should go when it differs from the From address.',
                'إلى أين تُوجَّه الردود عندما تختلف عن عنوان المرسل.',
                envKey: 'MAIL_REPLY_TO', configPath: 'mail.reply_to', group: 'identity'),
            $this->def('email.timeout', ConfigurationCategory::Email, ConfigurationType::Integer,
                'Connection timeout', 'مهلة الاتصال',
                'Seconds to wait for the SMTP server before giving up.',
                'عدد الثواني لانتظار خادم SMTP قبل التوقف.',
                default: 15, envKey: 'MAIL_TIMEOUT', configPath: 'mail.mailers.smtp.timeout', min: 1, max: 120, group: 'transport'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function storage(): array
    {
        return [
            $this->def('storage.driver', ConfigurationCategory::Storage, ConfigurationType::Select,
                'Storage driver', 'مشغل التخزين',
                'Local keeps media on this server. S3-compatible covers AWS S3, Cloudflare R2 and compatible services. Changing it does not move existing files.',
                'يحفظ المحلي الوسائط على هذا الخادم. يشمل المتوافق مع S3 خدمات AWS S3 وCloudflare R2 وما شابه. لا يغيّر التبديل الملفات الموجودة.',
                default: (string) config('filesystems.default', 'local'), envKey: 'FILESYSTEM_DISK', configPath: 'filesystems.default', options: ['local', 's3'], group: 'driver'),
            $this->def('storage.s3_key', ConfigurationCategory::Storage, ConfigurationType::Secret,
                'Access key', 'مفتاح الوصول',
                'The S3-compatible access key ID. Stored encrypted.',
                'معرف مفتاح الوصول المتوافق مع S3. يُخزن مشفرًا.',
                envKey: 'AWS_ACCESS_KEY_ID', configPath: 'filesystems.disks.s3.key', group: 's3'),
            $this->def('storage.s3_secret', ConfigurationCategory::Storage, ConfigurationType::Secret,
                'Secret key', 'المفتاح السري',
                'The S3-compatible secret key. Stored encrypted and never returned.',
                'المفتاح السري المتوافق مع S3. يُخزن مشفرًا ولا يُعاد أبدًا.',
                envKey: 'AWS_SECRET_ACCESS_KEY', configPath: 'filesystems.disks.s3.secret', group: 's3'),
            $this->def('storage.s3_region', ConfigurationCategory::Storage, ConfigurationType::String,
                'Region', 'المنطقة',
                'The bucket region, for example us-east-1. R2 uses auto where supported.',
                'منطقة الحاوية، مثل us-east-1. تستخدم R2 القيمة auto حيث تدعمها.',
                default: (string) config('filesystems.disks.s3.region', ''), envKey: 'AWS_DEFAULT_REGION', configPath: 'filesystems.disks.s3.region', maxLength: 60, group: 's3'),
            $this->def('storage.s3_bucket', ConfigurationCategory::Storage, ConfigurationType::String,
                'Bucket', 'الحاوية',
                'The bucket that receives uploaded media.',
                'الحاوية التي تستقبل الوسائط المرفوعة.',
                default: (string) config('filesystems.disks.s3.bucket', ''), envKey: 'AWS_BUCKET', configPath: 'filesystems.disks.s3.bucket', maxLength: 255, group: 's3'),
            $this->def('storage.s3_endpoint', ConfigurationCategory::Storage, ConfigurationType::Url,
                'Endpoint', 'نقطة النهاية',
                'Custom endpoint for R2 or another S3-compatible provider; leave empty for AWS.',
                'نقطة نهاية مخصصة لـ R2 أو مزود متوافق آخر؛ اتركها فارغة لـ AWS.',
                default: (string) config('filesystems.disks.s3.endpoint', ''), envKey: 'AWS_ENDPOINT', configPath: 'filesystems.disks.s3.endpoint', group: 's3'),
            $this->def('storage.s3_url', ConfigurationCategory::Storage, ConfigurationType::Url,
                'Public URL', 'الرابط العام',
                'The public base URL used to render uploaded media.',
                'الرابط العام الأساسي لعرض الوسائط المرفوعة.',
                default: (string) config('filesystems.disks.s3.url', ''), envKey: 'AWS_URL', configPath: 'filesystems.disks.s3.url', group: 's3'),
            $this->def('storage.s3_path_prefix', ConfigurationCategory::Storage, ConfigurationType::String,
                'Path prefix', 'بادئة المسار',
                'An optional root prefix inside the bucket, for example dukavo/media.',
                'بادئة جذر اختيارية داخل الحاوية، مثل dukavo/media.',
                default: (string) config('filesystems.disks.s3.root', ''), envKey: 'AWS_PATH_PREFIX', configPath: 'filesystems.disks.s3.root', maxLength: 255, group: 's3'),
            $this->def('storage.s3_use_path_style', ConfigurationCategory::Storage, ConfigurationType::Boolean,
                'Path-style endpoint', 'نقطة نهاية بنمط المسار',
                'Required by some S3-compatible providers and local object stores.',
                'تتطلبها بعض مزودي S3 المتوافقين ومخازن الكائنات المحلية.',
                default: (bool) config('filesystems.disks.s3.use_path_style_endpoint', false), envKey: 'AWS_USE_PATH_STYLE_ENDPOINT', configPath: 'filesystems.disks.s3.use_path_style_endpoint', group: 's3'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function security(): array
    {
        return [
            $this->def('security.password_min_length', ConfigurationCategory::Security, ConfigurationType::Integer,
                'Minimum password length', 'الحد الأدنى لطول كلمة المرور',
                'Applied to new passwords and password changes. Existing passwords are not invalidated.',
                'يُطبق على كلمات المرور الجديدة والتغييرات. لا يُبطل كلمات المرور الحالية.',
                default: (int) config('dukavo.security.password.min_length', 12), envKey: 'DUKAVO_PASSWORD_MIN_LENGTH', configPath: 'dukavo.security.password.min_length', min: 8, max: 128, group: 'password'),
            $this->def('security.password_require_letters', ConfigurationCategory::Security, ConfigurationType::Boolean,
                'Require letters', 'اشترط الحروف',
                'Passwords must contain at least one letter.',
                'يجب أن تحتوي كلمة المرور على حرف واحد على الأقل.',
                default: true, configPath: 'dukavo.security.password.require_letters', group: 'password'),
            $this->def('security.password_require_mixed_case', ConfigurationCategory::Security, ConfigurationType::Boolean,
                'Require mixed case', 'اشترط حالتين',
                'Passwords must contain upper and lower case letters.',
                'يجب أن تحتوي كلمة المرور على حروف كبيرة وصغيرة.',
                default: (bool) config('dukavo.security.password.require_mixed_case', true), envKey: 'DUKAVO_PASSWORD_MIXED_CASE', configPath: 'dukavo.security.password.require_mixed_case', group: 'password'),
            $this->def('security.password_require_numbers', ConfigurationCategory::Security, ConfigurationType::Boolean,
                'Require numbers', 'اشترط الأرقام',
                'Passwords must contain at least one number.',
                'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل.',
                default: (bool) config('dukavo.security.password.require_numbers', true), envKey: 'DUKAVO_PASSWORD_NUMBERS', configPath: 'dukavo.security.password.require_numbers', group: 'password'),
            $this->def('security.password_require_symbols', ConfigurationCategory::Security, ConfigurationType::Boolean,
                'Require symbols', 'اشترط الرموز',
                'Passwords must contain at least one symbol.',
                'يجب أن تحتوي كلمة المرور على رمز واحد على الأقل.',
                default: (bool) config('dukavo.security.password.require_symbols', true), envKey: 'DUKAVO_PASSWORD_SYMBOLS', configPath: 'dukavo.security.password.require_symbols', group: 'password'),
            $this->def('security.password_require_uncompromised', ConfigurationCategory::Security, ConfigurationType::Boolean,
                'Reject compromised passwords', 'ارفض كلمات المرور المسربة',
                'Checks new passwords against a public breach corpus. Disabling this lowers security and is audited.',
                'يفحص كلمات المرور الجديدة مقابل قاعدة تسريبات عامة. تعطيله يقلل الأمان ويُسجل في التدقيق.',
                default: (bool) config('dukavo.security.password.require_uncompromised', true), envKey: 'DUKAVO_PASSWORD_UNCOMPROMISED', configPath: 'dukavo.security.password.require_uncompromised', group: 'password'),
            $this->def('security.session_lifetime_minutes', ConfigurationCategory::Security, ConfigurationType::Integer,
                'Session lifetime', 'مدة الجلسة',
                'Minutes an idle session remains valid. Changing this affects new sessions.',
                'عدد دقائق بقاء الجلسة الخاملة صالحة. يؤثر التغيير على الجلسات الجديدة.',
                default: (int) config('session.lifetime', 120), envKey: 'SESSION_LIFETIME', configPath: 'session.lifetime', min: 5, max: 43200, group: 'session'),
            $this->def('security.login_rate_limit_per_minute', ConfigurationCategory::Security, ConfigurationType::Integer,
                'Login attempts per minute', 'محاولات الدخول في الدقيقة',
                'Per account and address. Raising it weakens brute-force protection.',
                'لكل حساب وعنوان. رفعه يضعف الحماية من التخمين.',
                default: (int) config('dukavo.security.login_rate_limit_per_minute', 5), envKey: 'DUKAVO_LOGIN_RATE_LIMIT', configPath: 'dukavo.security.login_rate_limit_per_minute', min: 1, max: 60, group: 'rate_limits'),
            $this->def('security.api_rate_limit_per_minute', ConfigurationCategory::Security, ConfigurationType::Integer,
                'API requests per minute', 'طلبات API في الدقيقة',
                'The general authenticated API budget, per account or address.',
                'ميزانية API العامة للمصادقين، لكل حساب أو عنوان.',
                default: (int) config('dukavo.rate_limits.api', 120), envKey: 'DUKAVO_API_RATE_LIMIT', configPath: 'dukavo.rate_limits.api', min: 10, max: 10000, group: 'rate_limits'),
            $this->def('security.write_rate_limit_per_minute', ConfigurationCategory::Security, ConfigurationType::Integer,
                'Write requests per minute', 'طلبات الكتابة في الدقيقة',
                'The tighter budget for endpoints that create or change data.',
                'الميزانية الأضيق للنقاط التي تنشئ أو تغير البيانات.',
                default: (int) config('dukavo.rate_limits.write', 30), envKey: 'DUKAVO_WRITE_RATE_LIMIT', configPath: 'dukavo.rate_limits.write', min: 5, max: 1000, group: 'rate_limits'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function maintenance(): array
    {
        return [
            $this->def('maintenance.enabled', ConfigurationCategory::Maintenance, ConfigurationType::Boolean,
                'Maintenance mode', 'وضع الصيانة',
                'Answers 503 for public and merchant traffic while platform operators stay signed in. Always reversible from this dashboard.',
                'يعيد 503 لحركة الزوار والتجار بينما يبقى مشغلو المنصة مسجلين. قابل للعكس دائمًا من هذه اللوحة.',
                default: false, envKey: 'DUKAVO_MAINTENANCE_ENABLED', configPath: 'dukavo.maintenance.enabled', group: 'state'),
            $this->def('maintenance.message', ConfigurationCategory::Maintenance, ConfigurationType::Text,
                'Maintenance message', 'رسالة الصيانة',
                'Shown to visitors and merchants while maintenance mode is on.',
                'تظهر للزوار والتجار أثناء تشغيل وضع الصيانة.',
                default: (string) config('dukavo.maintenance.message', 'Dukavo is undergoing scheduled maintenance. Please try again shortly.'), envKey: 'DUKAVO_MAINTENANCE_MESSAGE', configPath: 'dukavo.maintenance.message', maxLength: 500, group: 'state'),
            $this->def('maintenance.retry_after_seconds', ConfigurationCategory::Maintenance, ConfigurationType::Integer,
                'Retry-After header', 'ترويسة إعادة المحاولة',
                'Seconds a client is asked to wait before retrying during maintenance.',
                'عدد الثواني التي يُطلب من العميل الانتظار قبل إعادة المحاولة أثناء الصيانة.',
                default: (int) config('dukavo.maintenance.retry_after_seconds', 3600), envKey: 'DUKAVO_MAINTENANCE_RETRY_AFTER', configPath: 'dukavo.maintenance.retry_after_seconds', min: 30, max: 86400, group: 'state'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function ai(): array
    {
        return [
            $this->def('ai.request_timeout_seconds', ConfigurationCategory::Ai, ConfigurationType::Integer,
                'Request timeout', 'مهلة الطلب',
                'How long one provider request may run before it is abandoned.',
                'المدة التي قد يستغرقها طلب مزود واحد قبل التخلي عنه.',
                default: (int) config('ai.transport.timeout', 60), envKey: 'AI_TIMEOUT', configPath: 'ai.transport.timeout', min: 5, max: 300, group: 'runtime'),
            $this->def('ai.connect_timeout_seconds', ConfigurationCategory::Ai, ConfigurationType::Integer,
                'Connection timeout', 'مهلة الاتصال',
                'How long to wait for a provider connection.',
                'مدة الانتظار لاتصال المزود.',
                default: (int) config('ai.transport.connect_timeout', 5), envKey: 'AI_CONNECT_TIMEOUT', configPath: 'ai.transport.connect_timeout', min: 1, max: 60, group: 'runtime'),
            $this->def('ai.retry_times', ConfigurationCategory::Ai, ConfigurationType::Integer,
                'Retries per provider', 'المحاولات لكل مزود',
                'Same-provider retries before the chain falls back. Authentication and safety failures never retry.',
                'محاولات إعادة لنفس المزود قبل الانتقال للسلسلة البديلة. لا تُعاد أخطاء المصادقة والسلامة.',
                default: (int) config('ai.transport.retry_times', 2), envKey: 'AI_RETRY_TIMES', configPath: 'ai.transport.retry_times', min: 0, max: 3, group: 'runtime'),
            $this->def('ai.rate_limit_per_minute', ConfigurationCategory::Ai, ConfigurationType::Integer,
                'AI requests per minute', 'طلبات الذكاء الاصطناعي في الدقيقة',
                'A burst guard per identity, layered on top of daily quotas.',
                'حماية من الانفجار لكل هوية فوق الحصص اليومية.',
                default: (int) config('ai.transport.rate_per_minute', 20), envKey: 'AI_RATE_PER_MINUTE', configPath: 'ai.transport.rate_per_minute', min: 1, max: 600, group: 'runtime'),
            $this->def('ai.byok_enabled', ConfigurationCategory::Ai, ConfigurationType::Boolean,
                'Allow merchant-owned AI keys', 'السماح بمفاتيح ذكاء اصطناعي للتجار',
                'When enabled, a merchant may opt in to their own provider credential. Both switches must be on before it is used.',
                'عند التفعيل يمكن للتاجر الاشتراك بمفتاح مزود خاص به. يجب تفعيل المفتاحين قبل استخدامه.',
                default: (bool) config('ai.byok.enabled', false), envKey: 'AI_BYOK_ENABLED', configPath: 'ai.byok.enabled', group: 'routing'),
            $this->def('ai.fallback_enabled', ConfigurationCategory::Ai, ConfigurationType::Boolean,
                'Provider fallback', 'التبديل بين المزودين',
                'When a provider cannot serve a request, try the next eligible provider. Authentication and safety failures keep their own rules.',
                'عندما يتعذر على المزود خدمة الطلب، جرّب المزود التالي المؤهل. تحتفظ أخطاء المصادقة والسلامة بقواعدها.',
                default: (bool) config('ai.fallback.enabled', true), configPath: 'ai.fallback.enabled', group: 'routing'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function whatsapp(): array
    {
        return [
            $this->def('whatsapp.app_secret', ConfigurationCategory::Whatsapp, ConfigurationType::Secret,
                'Meta App Secret', 'سر تطبيق ميتا',
                'Validates X-Hub-Signature-256 on inbound webhooks. Stored encrypted; replacing it changes verification immediately.',
                'يتحقق من X-Hub-Signature-256 في الويبهوك الوارد. يُخزن مشفرًا؛ استبداله يغير التحقق فورًا.',
                envKey: 'WHATSAPP_APP_SECRET', configPath: 'services.whatsapp.app_secret', group: 'application'),
            $this->def('whatsapp.webhook_verify_token', ConfigurationCategory::Whatsapp, ConfigurationType::Secret,
                'Webhook verify token', 'رمز التحقق من الويبهوك',
                'The GET handshake token registered with Meta. Different from the app secret.',
                'رمز مصافحة GET المسجل لدى ميتا. يختلف عن سر التطبيق.',
                envKey: 'WHATSAPP_WEBHOOK_VERIFY_TOKEN', configPath: 'services.whatsapp.webhook_verify_token', group: 'application'),
            $this->def('whatsapp.cloud_api_token', ConfigurationCategory::Whatsapp, ConfigurationType::Secret,
                'Cloud API token', 'رمز Cloud API',
                'Authorises outgoing Graph API calls for the platform number. Merchant tokens remain on their own encrypted connection rows.',
                'يصرح باستدعاءات Graph API الصادرة لرقم المنصة. تبقى رموز التجار في صفوف اتصالاتهم المشفرة.',
                envKey: 'WHATSAPP_CLOUD_API_TOKEN', configPath: 'services.whatsapp.cloud_api_token', group: 'application'),
            $this->def('whatsapp.phone_number_id', ConfigurationCategory::Whatsapp, ConfigurationType::String,
                'Phone number ID', 'معرف رقم الهاتف',
                'The platform-owned WhatsApp number, if one is configured.',
                'رقم واتساب المملوك للمنصة، إن وُجد.',
                envKey: 'WHATSAPP_PHONE_NUMBER_ID', configPath: 'services.whatsapp.phone_number_id', maxLength: 80, group: 'number'),
            $this->def('whatsapp.business_account_id', ConfigurationCategory::Whatsapp, ConfigurationType::String,
                'Business account ID', 'معرف حساب الأعمال',
                'The WhatsApp Business Account that owns the platform number.',
                'حساب واتساب للأعمال المالك لرقم المنصة.',
                envKey: 'WHATSAPP_BUSINESS_ACCOUNT_ID', configPath: 'services.whatsapp.business_account_id', maxLength: 80, group: 'number'),
            $this->def('whatsapp.cloud_api_version', ConfigurationCategory::Whatsapp, ConfigurationType::String,
                'Graph API version', 'إصدار Graph API',
                'For example v26.0. Changing it takes effect on the next request.',
                'مثل v26.0. يسري التغيير على الطلب التالي.',
                default: (string) config('services.whatsapp.cloud_api_version', 'v26.0'), envKey: 'WHATSAPP_CLOUD_API_VERSION', configPath: 'services.whatsapp.cloud_api_version', maxLength: 20, group: 'runtime'),
            $this->def('whatsapp.api_base_url', ConfigurationCategory::Whatsapp, ConfigurationType::Url,
                'Graph API base URL', 'عنوان Graph API الأساسي',
                'The Graph host. Leave the default unless a proxy is required.',
                'مضيف Graph. اتركه افتراضيًا إلا إذا لزم وكيل.',
                default: (string) config('services.whatsapp.api_base_url', 'https://graph.facebook.com'), envKey: 'WHATSAPP_API_BASE_URL', configPath: 'services.whatsapp.api_base_url', group: 'runtime'),
            $this->def('whatsapp.messaging_window_hours', ConfigurationCategory::Whatsapp, ConfigurationType::Integer,
                'Messaging window', 'نافذة المراسلة',
                'Hours in which a free-form reply is allowed after the customer writes.',
                'الساعات المسموح فيها بالرد الحر بعد كتابة العميل.',
                default: (int) config('services.whatsapp.messaging_window_hours', 24), envKey: 'WHATSAPP_MESSAGING_WINDOW_HOURS', configPath: 'services.whatsapp.messaging_window_hours', min: 1, max: 72, group: 'runtime'),
            $this->def('whatsapp.media_max_bytes', ConfigurationCategory::Whatsapp, ConfigurationType::Integer,
                'Maximum media size', 'الحد الأقصى لحجم الوسائط',
                'The largest inbound or outbound WhatsApp attachment accepted.',
                'أكبر مرفق واتساب وارد أو صادر مقبول.',
                default: (int) config('services.whatsapp.media_max_bytes', 26214400), envKey: 'WHATSAPP_MEDIA_MAX_BYTES', configPath: 'services.whatsapp.media_max_bytes', min: 1024, max: 104857600, group: 'runtime'),
        ];
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function features(): array
    {
        return array_map(fn (array $flag): ConfigurationDefinition => $this->def(
            'features.'.$flag['key'],
            ConfigurationCategory::Features,
            ConfigurationType::Boolean,
            $flag['label'],
            $flag['labelAr'],
            $flag['description'],
            $flag['descriptionAr'],
            default: $flag['default'],
            group: $flag['group'],
        ), FeatureCatalogue::flags());
    }

    /**
     * @return list<ConfigurationDefinition>
     */
    private function infrastructure(): array
    {
        return [
            $this->infra('infrastructure.app_key', 'Application key', 'مفتاح التطبيق', 'Shown so you can see whether it is set. It cannot be read or changed here.', 'يظهر لمعرفة ما إذا كان مضبوطًا. لا يمكن قراءته أو تغييره هنا.', 'APP_KEY', 'app.key', true),
            $this->infra('infrastructure.app_env', 'Environment', 'البيئة', 'The deployment environment name (production, staging, local).', 'اسم بيئة النشر (production، staging، local).', 'APP_ENV', 'app.env'),
            $this->infra('infrastructure.app_debug', 'Debug mode', 'وضع التصحيح', 'Must stay off in production. Change it in the deployment environment only.', 'يجب أن يبقى معطلًا في الإنتاج. غيّره في بيئة النشر فقط.', 'APP_DEBUG', 'app.debug'),
            $this->infra('infrastructure.app_url', 'Application URL', 'عنوان التطبيق', 'The API base URL, used for signed and public links.', 'عنوان API الأساسي المستخدم في الروابط الموقعة والعامة.', 'APP_URL', 'app.url'),
            $this->infra('infrastructure.database_connection', 'Database connection', 'اتصال قاعدة البيانات', 'The driver name.', 'اسم المشغل.', 'DB_CONNECTION', 'database.default'),
            $this->infra('infrastructure.database_host', 'Database host', 'مضيف قاعدة البيانات', 'The database server.', 'خادم قاعدة البيانات.', 'DB_HOST', 'database.connections.mysql.host'),
            $this->infra('infrastructure.database_name', 'Database name', 'اسم قاعدة البيانات', 'The schema Dukavo uses.', 'المخطط الذي تستخدمه دوكافو.', 'DB_DATABASE', 'database.connections.mysql.database'),
            $this->infra('infrastructure.database_username', 'Database user', 'مستخدم قاعدة البيانات', 'The database account.', 'حساب قاعدة البيانات.', 'DB_USERNAME', 'database.connections.mysql.username'),
            $this->infra('infrastructure.database_password', 'Database password', 'كلمة مرور قاعدة البيانات', 'Never displayed. Rotate it in the deployment environment.', 'لا تُعرض أبدًا. بدّلها في بيئة النشر.', 'DB_PASSWORD', 'database.connections.mysql.password', true),
            $this->infra('infrastructure.cache_store', 'Cache store', 'مخزن التخزين المؤقت', 'A database store is supported; Redis is not assumed.', 'مخزن قاعدة البيانات مدعوم؛ لا يُفترض وجود Redis.', 'CACHE_STORE', 'cache.default'),
            $this->infra('infrastructure.queue_connection', 'Queue connection', 'اتصال قائمة الانتظار', 'The queue driver. A worker must be running for queued work to execute.', 'مشغل قائمة الانتظار. يجب تشغيل عامل لتنفيذ الأعمال المجدولة.', 'QUEUE_CONNECTION', 'queue.default'),
            $this->infra('infrastructure.session_driver', 'Session driver', 'مشغل الجلسات', 'The session backend.', 'خلفية الجلسات.', 'SESSION_DRIVER', 'session.driver'),
        ];
    }

    /* ---------------------------------------------------------------- helpers */

    private function def(
        string $key,
        ConfigurationCategory $category,
        ConfigurationType $type,
        string $label,
        string $labelAr,
        string $description,
        string $descriptionAr,
        mixed $default = null,
        ?string $envKey = null,
        ?string $configPath = null,
        array $options = [],
        ?int $min = null,
        ?int $max = null,
        ?int $maxLength = null,
        ?string $group = null,
        bool $requiresRestart = false,
    ): ConfigurationDefinition {
        return new ConfigurationDefinition(
            key: $key,
            category: $category,
            type: $type,
            label: $label,
            labelAr: $labelAr,
            description: $description,
            descriptionAr: $descriptionAr,
            default: $default,
            envKey: $envKey,
            configPath: $configPath,
            requiresRestart: $requiresRestart,
            sourcePolicy: ConfigurationSourcePolicy::DatabasePreferred,
            options: $options,
            min: $min,
            max: $max,
            maxLength: $maxLength,
            group: $group,
        );
    }

    private function infra(
        string $key,
        string $label,
        string $labelAr,
        string $description,
        string $descriptionAr,
        string $envKey,
        string $configPath,
        bool $secret = false,
    ): ConfigurationDefinition {
        return new ConfigurationDefinition(
            key: $key,
            category: ConfigurationCategory::Infrastructure,
            type: $secret ? ConfigurationType::Secret : ConfigurationType::String,
            label: $label,
            labelAr: $labelAr,
            description: $description,
            descriptionAr: $descriptionAr,
            default: null,
            envKey: $envKey,
            configPath: $configPath,
            requiresRestart: true,
            sourcePolicy: ConfigurationSourcePolicy::EnvironmentOnly,
        );
    }
}
