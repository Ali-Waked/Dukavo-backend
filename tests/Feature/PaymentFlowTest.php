<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentConnectionStatus;
use App\Enums\PaymentRefundStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentRefund;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StorePaymentAccount;
use App\Models\Tenant;
use Database\Seeders\PaymentCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end online payment flow against a faked Tap sandbox.
 *
 * The tests prove the parts that must never be trusted to a browser: the
 * server-priced amount, the provider redirect, the signed callback, duplicate
 * delivery, amount/currency mismatches and the refund ceiling.
 */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Product $product;

    private ProductVariant $variant;

    private StorePaymentAccount $account;

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
            'storefront_enabled' => true,
        ])->save();

        ['product' => $this->product, 'variant' => $this->variant] = $this->stockedProduct($this->tenant, $this->store);

        $this->withinTenant($this->tenant, function (): void {
            $this->product->forceFill(['currency' => 'SAR'])->save();
        });

        $this->account = $this->connection();
    }

    public function test_online_checkout_redirects_to_the_provider_without_marking_the_order_paid(): void
    {
        $this->fakeCharge();

        $response = $this->checkout()->assertCreated();

        $response
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.checkout_url', 'https://checkout.tap.test/ch_test_123')
            ->assertJsonPath('data.payment.method_code', 'card')
            ->assertJsonPath('data.payment.retry_allowed', false)
            ->assertJsonPath('data.grand_total', 10);

        $payment = $this->payment();

        $this->assertSame(PaymentAttemptStatus::Pending, $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertSame('ch_test_123', $payment->provider_reference);
        $this->assertSame('SAR', $payment->currency);
        $this->assertSame('10.0000', (string) $payment->amount);
        $this->assertSame($this->account->getKey(), $payment->store_payment_account_id);
        $this->assertSame(PaymentStatus::Unpaid, $this->order()->payment_status);
    }

    public function test_a_provider_refusal_leaves_a_retryable_failed_attempt_on_a_real_order(): void
    {
        Http::fake([
            'api.tap.company/v2/charges*' => Http::response([
                'message' => 'The card was declined.',
                'status' => 'DECLINED',
            ], 402),
        ]);

        $response = $this->checkout()->assertCreated();

        $response
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.retry_allowed', true);

        $this->assertSame(PaymentAttemptStatus::Failed, $this->payment()->status);
        $this->assertSame(PaymentStatus::Unpaid, $this->order()->payment_status);
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_a_signed_captured_webhook_pays_the_order_and_is_idempotent(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $payment = $this->payment();
        $payload = $this->capturedPayload($payment, $payment->amount);

        $this->postWebhook($payload, $this->signature($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertSame(PaymentAttemptStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $this->order()->payment_status);
        $this->assertSame(1, PaymentWebhookEvent::query()->count());

        // The provider may redeliver the identical body; it must not repeat the
        // financial effect or create a second event.
        $this->postWebhook($payload, $this->signature($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(PaymentStatus::Paid, $this->order()->payment_status);
    }

    public function test_a_callback_with_an_invalid_signature_cannot_move_money(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $payload = $this->capturedPayload($this->payment(), $this->payment()->amount);

        $this->postWebhook($payload, 'not-a-valid-signature')
            ->assertStatus(400);

        $this->assertSame(PaymentAttemptStatus::Pending, $this->payment()->status);
        $this->assertSame(PaymentStatus::Unpaid, $this->order()->payment_status);

        $event = PaymentWebhookEvent::query()->firstOrFail();
        $this->assertFalse($event->signature_valid);
        $this->assertSame('failed', $event->status->value);
    }

    public function test_a_signed_callback_with_the_wrong_amount_does_not_pay_the_order(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $payload = $this->capturedPayload($this->payment(), '99.00');

        $this->postWebhook($payload, $this->signature($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(PaymentAttemptStatus::Failed, $this->payment()->status);
        $this->assertSame(PaymentStatus::Unpaid, $this->order()->payment_status);
        $this->assertSame(0, DB::table('payment_transactions')->where('status', 'succeeded')->count());
    }

    public function test_a_signed_callback_with_the_wrong_currency_does_not_pay_the_order(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $payment = $this->payment();
        $payload = $this->capturedPayload($payment, $payment->amount);
        $payload['currency'] = 'USD';

        $this->postWebhook($payload, $this->signature($payload))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(PaymentAttemptStatus::Failed, $this->payment()->status);
        $this->assertSame(PaymentStatus::Unpaid, $this->order()->payment_status);
    }

    public function test_a_full_refund_is_bounded_by_the_captured_charge(): void
    {
        $this->payForOrder();

        Http::fake([
            'api.tap.company/v2/refunds*' => Http::response([
                'id' => 'rf_test_1',
                'status' => 'REFUNDED',
            ], 200),
        ]);

        $user = $this->memberOf($this->tenant);

        $this->actingAs($user)
            ->postJson('/api/v1/tenant/payments/'.$this->payment()->getKey().'/refunds', [
                'amount' => '10.00',
                'reason' => 'Customer returned the item',
                'idempotency_key' => 'refund-full-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.amount', 10)
            ->assertJsonPath('data.provider_refund_id', 'rf_test_1');

        $refund = $this->withinTenant(
            $this->tenant,
            fn (): PaymentRefund => PaymentRefund::query()
                ->where('payment_id', $this->payment()->getKey())
                ->firstOrFail(),
        );

        $this->assertSame(PaymentRefundStatus::Succeeded, $refund->status);
        $this->assertSame(PaymentStatus::Refunded, $this->order()->payment_status);

        // A second refund would exceed the charge and is refused before a
        // provider call is attempted.
        $this->actingAs($user)
            ->postJson('/api/v1/tenant/payments/'.$this->payment()->getKey().'/refunds', [
                'amount' => '0.01',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'refund_exceeds_balance');
    }

    public function test_a_refund_cannot_be_sent_before_the_charge_is_captured(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $user = $this->memberOf($this->tenant);

        Http::fake();

        $this->actingAs($user)
            ->postJson('/api/v1/tenant/payments/'.$this->payment()->getKey().'/refunds', [
                'amount' => '1.00',
            ])
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_merchant_connections_never_return_or_store_plaintext_credentials(): void
    {
        $user = $this->memberOf($this->tenant);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenant/stores/'.$this->store->getKey().'/payment-connections')
            ->assertOk();

        $this->assertStringNotContainsString('sk_test_flow', $response->getContent());
        $this->assertStringNotContainsString('whsec_flow', $response->getContent());
        $this->assertStringNotContainsString('"credentials"', $response->getContent());

        $raw = DB::table('store_payment_accounts')->where('id', $this->account->getKey())->first();

        $this->assertStringNotContainsString('sk_test_flow', (string) $raw->credentials);
        $this->assertStringNotContainsString('whsec_flow', (string) $raw->webhook_secret);
    }

    public function test_saving_a_connection_verifies_it_with_the_provider_before_activating(): void
    {
        $this->account->forceFill([
            'status' => PaymentConnectionStatus::Disabled,
            'last_verified_at' => null,
        ])->save();

        Http::fake([
            'api.tap.company/v2/charges*' => Http::response(['data' => []], 200),
        ]);

        $user = $this->memberOf($this->tenant);

        $this->actingAs($user)
            ->postJson('/api/v1/tenant/stores/'.$this->store->getKey().'/payment-connections', [
                'provider_code' => 'tap',
                'environment' => 'sandbox',
                'credentials' => ['secret_key' => 'sk_test_new_key'],
                'webhook_secret' => 'whsec_new_key',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.has_webhook_secret', true);

        $this->assertSame(PaymentConnectionStatus::Active, $this->account->refresh()->status);
    }

    public function test_a_merchant_cannot_reach_platform_payment_administration(): void
    {
        $user = $this->memberOf($this->tenant);

        $this->actingAs($user)
            ->getJson('/api/v1/platform/payments/providers')
            ->assertForbidden();
    }

    public function test_another_tenant_cannot_read_a_payment_by_id(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $other = $this->tenantWithStore();
        $otherUser = $this->memberOf($other);

        $this->actingAs($otherUser)
            ->getJson('/api/v1/tenant/payments/'.$this->payment()->getKey())
            ->assertNotFound();
    }

    private function checkout()
    {
        return $this->postJson(
            '/api/v1/public/storefront/'.$this->tenant->slug.'/'.$this->store->slug.'/checkout',
            [
                'customer_name' => 'Nour Customer',
                'customer_phone' => '+966500000000',
                'customer_email' => 'nour@example.test',
                'payment_method' => 'online',
                'payment_method_code' => 'card',
                'idempotency_key' => 'online-checkout-'.uniqid('', true),
                'shipping_address' => [
                    'contact_name' => 'Nour Customer',
                    'line_one' => '12 King Road',
                    'city' => 'Riyadh',
                    'country' => 'SA',
                ],
                'items' => [
                    ['product_variant_id' => $this->variant->getKey(), 'quantity' => 1],
                ],
            ],
        );
    }

    private function fakeCharge(): void
    {
        Http::fake([
            'api.tap.company/v2/charges*' => Http::response([
                'id' => 'ch_test_123',
                'status' => 'INITIATED',
                'amount' => 10.0,
                'currency' => 'SAR',
                'transaction' => ['url' => 'https://checkout.tap.test/ch_test_123'],
            ], 200),
        ]);
    }

    private function payForOrder(): void
    {
        $this->fakeCharge();
        $this->checkout()->assertCreated();

        $payment = $this->payment();
        $payload = $this->capturedPayload($payment, $payment->amount);

        $this->postWebhook($payload, $this->signature($payload))->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedPayload(Payment $payment, mixed $amount): array
    {
        return [
            'id' => 'ch_test_123',
            'object' => 'charge',
            'status' => 'CAPTURED',
            'amount' => $amount,
            'currency' => 'SAR',
            'gateway_reference' => 'gw_test',
            'payment_reference' => 'pr_test',
            'created' => '2026-11-10T10:00:00Z',
            'reference' => [
                'transaction' => $payment->client_reference,
                'order' => Order::query()->acrossTenants()->where('id', $payment->order_id)->firstOrFail()->order_number,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signature(array $payload): string
    {
        $amount = $payload['amount'];

        $string = 'x_id'.$payload['id']
            .'x_amount'.$amount
            .'x_currency'.$payload['currency']
            .'x_gateway_reference'.$payload['gateway_reference']
            .'x_payment_reference'.$payload['payment_reference']
            .'x_status'.$payload['status']
            .'x_created'.$payload['created'];

        return hash_hmac('sha256', $string, 'whsec_flow');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, string $signature)
    {
        return $this->postJson('/api/v1/webhooks/payments/tap', $payload, [
            'hashstring' => $signature,
        ]);
    }

    private function connection(): StorePaymentAccount
    {
        $provider = PaymentProvider::query()->where('code', 'tap')->firstOrFail();

        return $this->withinTenant($this->tenant, fn (): StorePaymentAccount => StorePaymentAccount::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'provider_id' => $provider->getKey(),
            'status' => PaymentConnectionStatus::Active,
            'environment' => 'sandbox',
            'credentials' => ['secret_key' => 'sk_test_flow'],
            'webhook_secret' => 'whsec_flow',
            'last_verified_at' => now(),
        ]));
    }

    private function payment(): Payment
    {
        return Payment::query()->acrossTenants()->latest('id')->firstOrFail();
    }

    private function order(): Order
    {
        return Order::query()->acrossTenants()->latest('id')->firstOrFail();
    }
}
