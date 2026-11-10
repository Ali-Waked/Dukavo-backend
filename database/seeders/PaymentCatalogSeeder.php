<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentProviderStatus;
use App\Models\PaymentCountry;
use App\Models\PaymentMethodDefinition;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCapability;
use App\Models\PaymentProviderCountry;
use App\Models\PaymentProviderMethod;
use App\Services\Payments\Providers\TapPaymentProvider;
use Illuminate\Database\Seeder;

/**
 * Seeds the provider-independent payment catalogue.
 *
 * Countries are all 22 Arab markets. Provider coverage is only written where it
 * is backed by the provider's own documentation; an unsupported market stays
 * intentionally absent rather than being guessed. Tap has an adapter, so it is
 * offered for the six GCC markets where it accepts new merchants (and recorded
 * as transaction-capable but closed to new merchants in Egypt and Jordan).
 * MyFatoorah coverage is documented but its integration is disabled until an
 * adapter exists.
 */
class PaymentCatalogSeeder extends Seeder
{
    private const TAP_SUPPORT_URL = 'https://support.tap.company/en/support/solutions/articles/153000140298-what-countries-are-supported-by-tap-payments-';

    private const TAP_DOCS_URL = 'https://developers.tap.company/reference/create-a-charge';

    private const TAP_WEBHOOK_URL = 'https://developers.tap.company/docs/webhook';

    private const MYFATOORAH_URL = 'https://docs.myfatoorah.com/docs/live-account';

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const COUNTRIES = [
        ['SA', 'Saudi Arabia', 'المملكة العربية السعودية', 'SAR'],
        ['AE', 'United Arab Emirates', 'الإمارات العربية المتحدة', 'AED'],
        ['KW', 'Kuwait', 'الكويت', 'KWD'],
        ['BH', 'Bahrain', 'البحرين', 'BHD'],
        ['QA', 'Qatar', 'قطر', 'QAR'],
        ['OM', 'Oman', 'عُمان', 'OMR'],
        ['EG', 'Egypt', 'مصر', 'EGP'],
        ['JO', 'Jordan', 'الأردن', 'JOD'],
        ['LB', 'Lebanon', 'لبنان', 'LBP'],
        ['IQ', 'Iraq', 'العراق', 'IQD'],
        ['SY', 'Syria', 'سوريا', 'SYP'],
        ['YE', 'Yemen', 'اليمن', 'YER'],
        ['PS', 'Palestine', 'فلسطين', 'ILS'],
        ['LY', 'Libya', 'ليبيا', 'LYD'],
        ['TN', 'Tunisia', 'تونس', 'TND'],
        ['DZ', 'Algeria', 'الجزائر', 'DZD'],
        ['MA', 'Morocco', 'المغرب', 'MAD'],
        ['MR', 'Mauritania', 'موريتانيا', 'MRU'],
        ['SO', 'Somalia', 'الصومال', 'SOS'],
        ['DJ', 'Djibouti', 'جيبوتي', 'DJF'],
        ['KM', 'Comoros', 'جزر القمر', 'KMF'],
        ['SD', 'Sudan', 'السودان', 'SDG'],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>
     */
    private const METHODS = [
        ['card', 'Card', 'بطاقة', 'Pay with a debit or credit card.', 'ادفع ببطاقة الخصم أو الائتمان.', 10],
        ['mada', 'Mada', 'مدى', 'Saudi Mada debit card.', 'بطاقة مدى السعودية.', 20],
        ['knet', 'KNET', 'كي نت', 'Kuwaiti KNET debit card.', 'بطاقة كي نت الكويتية.', 30],
        ['benefit', 'Benefit', 'بنفت', 'Bahraini Benefit debit card.', 'بطاقة بنفت البحرينية.', 40],
        ['qpay', 'QPay', 'كيو باي', 'Qatari QPay debit card.', 'بطاقة كيو باي القطرية.', 50],
        ['omannet', 'OmanNet', 'عمان نت', 'Omani OmanNet debit card.', 'بطاقة عمان نت العُمانية.', 60],
        ['apple_pay', 'Apple Pay', 'آبل باي', 'Pay with Apple Pay.', 'ادفع باستخدام آبل باي.', 70],
        ['stc_pay', 'STC Pay', 'إس تي سي باي', 'Pay with an STC Pay wallet.', 'ادفع بمحفظة إس تي سي باي.', 80],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: bool, 3: bool, 4: string}>
     */
    private const TAP_COVERAGE = [
        ['SA', 'SAR', true, true, 'New merchants accepted.'],
        ['AE', 'AED', true, true, 'New merchants accepted.'],
        ['KW', 'KWD', true, true, 'New merchants accepted.'],
        ['BH', 'BHD', true, true, 'New merchants accepted.'],
        ['QA', 'QAR', true, true, 'New merchants accepted.'],
        ['OM', 'OMR', true, true, 'New merchants accepted.'],
        ['EG', 'EGP', false, true, 'Existing merchants only; new onboarding closed.'],
        ['JO', 'JOD', false, true, 'Existing merchants only; new onboarding closed.'],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const MYFATOORAH_COVERAGE = [
        ['KW', 'KWD', 'Kuwait'],
        ['SA', 'SAR', 'Saudi Arabia'],
        ['BH', 'BHD', 'Bahrain'],
        ['AE', 'AED', 'United Arab Emirates'],
        ['QA', 'QAR', 'Qatar'],
        ['EG', 'EGP', 'Egypt'],
        ['OM', 'OMR', 'Oman'],
        ['JO', 'JOD', 'Jordan'],
    ];

    public function run(): void
    {
        $this->countries();
        $this->methods();

        $tap = $this->tap();
        $this->myFatoorah();
        $this->tapMethods($tap);
    }

    private function countries(): void
    {
        foreach (self::COUNTRIES as $index => [$code, $name, $nameAr, $currency]) {
            PaymentCountry::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'name_ar' => $nameAr,
                    'currency' => $currency,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }

    private function methods(): void
    {
        foreach (self::METHODS as [$code, $name, $nameAr, $description, $descriptionAr, $sort]) {
            PaymentMethodDefinition::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'name_ar' => $nameAr,
                    'description' => $description,
                    'description_ar' => $descriptionAr,
                    'kind' => $code === 'apple_pay' || $code === 'stc_pay' ? 'wallet' : 'card',
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }
    }

    private function tap(): PaymentProvider
    {
        $provider = PaymentProvider::query()->updateOrCreate(
            ['code' => 'tap'],
            [
                'name' => 'Tap Payments',
                'status' => PaymentProviderStatus::Active,
                'integration_enabled' => true,
                'adapter' => TapPaymentProvider::class,
                'docs_url' => self::TAP_DOCS_URL,
                'webhook_docs_url' => self::TAP_WEBHOOK_URL,
                'notes' => 'Sandbox-ready adapter. A merchant must supply their own approved Tap secret key; live charging requires Tap merchant approval.',
                'last_verified_at' => now(),
            ],
        );

        foreach (['charges', 'status', 'refunds', 'webhooks', 'connection_test'] as $capability) {
            PaymentProviderCapability::query()->updateOrCreate([
                'provider_id' => $provider->getKey(),
                'capability' => $capability,
            ]);
        }

        foreach (self::TAP_COVERAGE as [$country, $currency, $onboarding, $transactions, $note]) {
            PaymentProviderCountry::query()->updateOrCreate(
                ['provider_id' => $provider->getKey(), 'country_code' => $country],
                [
                    'settlement_currency' => $currency,
                    'merchant_onboarding' => $onboarding,
                    'transaction_capable' => $transactions,
                    'source_url' => self::TAP_SUPPORT_URL,
                    'notes' => $note,
                    'verified_at' => now(),
                ],
            );
        }

        return $provider;
    }

    private function myFatoorah(): void
    {
        $provider = PaymentProvider::query()->updateOrCreate(
            ['code' => 'myfatoorah'],
            [
                'name' => 'MyFatoorah',
                'status' => PaymentProviderStatus::Pending,
                'integration_enabled' => false,
                'adapter' => null,
                'docs_url' => self::MYFATOORAH_URL,
                'webhook_docs_url' => null,
                'notes' => 'Documented coverage recorded for planning. No adapter exists yet, so it is not offered to merchants.',
                'last_verified_at' => now(),
            ],
        );

        foreach (self::MYFATOORAH_COVERAGE as [$country, $currency, $name]) {
            PaymentProviderCountry::query()->updateOrCreate(
                ['provider_id' => $provider->getKey(), 'country_code' => $country],
                [
                    'settlement_currency' => $currency,
                    'merchant_onboarding' => true,
                    'transaction_capable' => true,
                    'source_url' => self::MYFATOORAH_URL,
                    'notes' => 'Documented live-account market: '.$name.'. Integration disabled until an adapter is approved.',
                    'verified_at' => now(),
                ],
            );
        }
    }

    private function tapMethods(PaymentProvider $provider): void
    {
        $methods = [
            ['card', null, 'src_card', 10],
            ['apple_pay', null, 'src_apple_pay', 20],
            ['knet', 'KW', 'src_kw.knet', 10],
            ['benefit', 'BH', 'src_bh.benefit', 10],
            ['omannet', 'OM', 'src_omannet', 10],
        ];

        foreach ($methods as [$method, $country, $sourceId, $priority]) {
            PaymentProviderMethod::query()->updateOrCreate(
                [
                    'provider_id' => $provider->getKey(),
                    'method_code' => $method,
                    'country_code' => $country,
                    'source_id' => $sourceId,
                ],
                [
                    'priority' => $priority,
                    'is_active' => true,
                ],
            );
        }
    }
}
