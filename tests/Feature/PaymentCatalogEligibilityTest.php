<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentConnectionStatus;
use App\Enums\PaymentProviderStatus;
use App\Models\PaymentCountry;
use App\Models\PaymentProvider;
use App\Models\PaymentProviderCountry;
use App\Models\PaymentProviderMethod;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\Tenant;
use App\Services\Payments\Data\PaymentOption;
use App\Services\Payments\PaymentEligibilityService;
use Database\Seeders\PaymentCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The provider-independent catalogue and the fail-closed eligibility rules.
 *
 * A market is never offered because a customer's browser said so; it is offered
 * only when the platform has documented coverage, the merchant has a verified
 * connection and the provider has an active source for the method.
 */
class PaymentCatalogEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(PaymentCatalogSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->store->forceFill([
            'country' => 'SA',
            'currency' => 'SAR',
        ])->save();
    }

    public function test_the_catalogue_contains_all_twenty_two_arab_countries(): void
    {
        $this->assertSame(22, PaymentCountry::query()->count());

        foreach (['SA', 'AE', 'KW', 'BH', 'QA', 'OM', 'EG', 'JO', 'LB', 'IQ', 'SY', 'YE', 'PS', 'LY', 'TN', 'DZ', 'MA', 'MR', 'SO', 'DJ', 'KM', 'SD'] as $code) {
            $this->assertDatabaseHas('payment_countries', ['code' => $code, 'is_active' => true]);
        }
    }

    public function test_tap_coverage_distinguishes_onboarding_from_transaction_capability(): void
    {
        $tap = PaymentProvider::query()->where('code', 'tap')->firstOrFail();

        $this->assertSame(PaymentProviderStatus::Active, $tap->status);
        $this->assertTrue($tap->integration_enabled);

        $saudi = PaymentProviderCountry::query()
            ->where('provider_id', $tap->getKey())
            ->where('country_code', 'SA')
            ->firstOrFail();
        $this->assertTrue($saudi->merchant_onboarding);
        $this->assertTrue($saudi->transaction_capable);

        $egypt = PaymentProviderCountry::query()
            ->where('provider_id', $tap->getKey())
            ->where('country_code', 'EG')
            ->firstOrFail();
        $this->assertFalse($egypt->merchant_onboarding);
        $this->assertTrue($egypt->transaction_capable);

        $this->assertDatabaseMissing('payment_provider_countries', [
            'provider_id' => $tap->getKey(),
            'country_code' => 'PS',
        ]);
    }

    public function test_myfatoorah_coverage_is_recorded_but_never_offered_without_an_adapter(): void
    {
        $provider = PaymentProvider::query()->where('code', 'myfatoorah')->firstOrFail();

        $this->assertSame(PaymentProviderStatus::Pending, $provider->status);
        $this->assertFalse($provider->integration_enabled);
        $this->assertNull($provider->adapter);
        $this->assertSame(8, $provider->countries()->count());
    }

    public function test_provider_source_ids_are_seeded_per_country(): void
    {
        $tap = PaymentProvider::query()->where('code', 'tap')->firstOrFail();

        $this->assertDatabaseHas('payment_provider_methods', [
            'provider_id' => $tap->getKey(),
            'method_code' => 'card',
            'country_code' => null,
            'source_id' => 'src_card',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('payment_provider_methods', [
            'provider_id' => $tap->getKey(),
            'method_code' => 'knet',
            'country_code' => 'KW',
            'source_id' => 'src_kw.knet',
        ]);

        $this->assertSame(5, PaymentProviderMethod::query()->where('provider_id', $tap->getKey())->count());
    }

    public function test_a_store_without_a_country_cannot_offer_online_payment(): void
    {
        $this->store->forceFill(['country' => null])->save();

        [$options, $reasons] = $this->evaluate($this->store->refresh());

        $this->assertSame([], $options);
        $this->assertStringContainsString('store country', $reasons[0]);
    }

    public function test_a_market_without_provider_coverage_cannot_offer_online_payment(): void
    {
        $this->store->forceFill(['country' => 'PS', 'currency' => 'ILS'])->save();

        [$options] = $this->evaluate($this->store->refresh());

        $this->assertSame([], $options);
    }

    public function test_an_unverified_connection_does_not_enable_online_payment(): void
    {
        $this->connection(status: PaymentConnectionStatus::Pending, verified: false);

        [$options] = $this->evaluate($this->store);

        $this->assertSame([], $options);
    }

    public function test_a_verified_active_connection_exposes_the_local_methods(): void
    {
        $this->connection();

        $options = $this->optionsOf($this->store);
        $codes = array_map(static fn ($option): string => $option->method->code, $options);

        sort($codes);

        $this->assertSame(['apple_pay', 'card'], $codes);

        foreach ($options as $option) {
            $this->assertSame($this->store->getKey(), $option->account->store_id);
            $this->assertSame('tap', $option->provider->code);
            $this->assertSame('sandbox', $option->account->environment);
        }
    }

    public function test_a_settlement_currency_mismatch_blocks_online_payment(): void
    {
        $this->store->forceFill(['currency' => 'USD'])->save();
        $this->connection();

        [$options, $reasons] = $this->evaluate($this->store->refresh());

        $this->assertSame([], $options);
        $this->assertStringContainsString('settles SAR', $reasons[0]);
    }

    public function test_a_connection_in_a_different_environment_is_ignored(): void
    {
        $this->connection(environment: 'live');

        [$options] = $this->evaluate($this->store);

        $this->assertSame([], $options);
    }

    public function test_disabling_a_provider_method_removes_it_from_checkout(): void
    {
        $this->connection();

        $tap = PaymentProvider::query()->where('code', 'tap')->firstOrFail();

        PaymentProviderMethod::query()
            ->where('provider_id', $tap->getKey())
            ->where('method_code', 'card')
            ->update(['is_active' => false]);

        $codes = array_map(
            static fn ($option): string => $option->method->code,
            $this->optionsOf($this->store),
        );

        $this->assertNotContains('card', $codes);
        $this->assertContains('apple_pay', $codes);
    }

    /**
     * @return array{0: list<PaymentOption>, 1: list<string>}
     */
    private function evaluate(Store $store): array
    {
        return $this->withinTenant(
            $this->tenant,
            fn (): array => $this->app->make(PaymentEligibilityService::class)->evaluate($store),
        );
    }

    /**
     * @return list<PaymentOption>
     */
    private function optionsOf(Store $store): array
    {
        return $this->withinTenant(
            $this->tenant,
            fn (): array => $this->app->make(PaymentEligibilityService::class)->options($store),
        );
    }

    private function connection(
        PaymentConnectionStatus $status = PaymentConnectionStatus::Active,
        bool $verified = true,
        string $environment = 'sandbox',
    ): StorePaymentAccount {
        $provider = PaymentProvider::query()->where('code', 'tap')->firstOrFail();

        return $this->withinTenant($this->tenant, fn (): StorePaymentAccount => StorePaymentAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'provider_id' => $provider->getKey(),
            'status' => $status,
            'environment' => $environment,
            'credentials' => ['secret_key' => 'sk_test_catalogue'],
            'webhook_secret' => 'whsec_catalogue',
            'last_verified_at' => $verified ? now() : null,
        ]));
    }
}
