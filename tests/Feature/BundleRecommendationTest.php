<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Bundles\BundleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * Recommendation evidence must be real, labelled and tenant-bound.
 *
 * A rule-based category match is allowed, but it must say so; an order count is
 * allowed, but it must come from this tenant's non-cancelled orders inside the
 * window; and the AI explanation must not turn either into a fabricated
 * conversion rate.
 */
class BundleRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->category = $this->withinTenant($this->tenant, fn (): Category => Category::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
        ]));
    }

    public function test_a_merchant_curated_bundle_is_recommended_with_curated_evidence(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->productInCategory('Partner');
        $bundle = $this->bundle($anchor, $partner);

        $response = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.kind', 'bundle')
            ->assertJsonPath('data.recommendations.0.source', 'merchant_curated')
            ->assertJsonPath('data.recommendations.0.evidence.basis', 'merchant_curated')
            ->assertJsonPath('data.recommendations.0.evidence.confidence', 'curated')
            ->assertJsonPath('data.recommendations.0.bundle.id', $bundle->getKey())
            ->json('data');

        $this->assertNotEmpty($response['catalog_fingerprint']);
        $this->assertNull($response['recommendations'][0]['evidence']['order_count']);
    }

    public function test_measured_co_purchases_are_counted_from_non_cancelled_orders_only(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->productInCategory('Partner');

        $this->orderWith([$anchor, $partner]);
        $this->orderWith([$anchor, $partner]);
        $this->orderWith([$anchor, $partner], OrderStatus::Cancelled);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.kind', 'suggested')
            ->assertJsonPath('data.recommendations.0.evidence.basis', 'purchased_together')
            ->assertJsonPath('data.recommendations.0.evidence.confidence', 'measured')
            ->assertJsonPath('data.recommendations.0.evidence.order_count', 2)
            ->assertJsonPath('data.recommendations.0.evidence.unit_count', 2)
            ->assertJsonPath('data.recommendations.0.evidence.window_days', 180);
    }

    public function test_same_category_is_labelled_as_a_rule_based_fallback(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->productInCategory('Partner');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.kind', 'suggested')
            ->assertJsonPath('data.recommendations.0.evidence.basis', 'same_category')
            ->assertJsonPath('data.recommendations.0.evidence.confidence', 'rule_based')
            ->assertJsonPath('data.recommendations.0.evidence.order_count', null)
            ->assertJsonPath('data.recommendations.0.items.1.product_id', $partner['product']->getKey());
    }

    public function test_an_out_of_stock_partner_is_not_recommended(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->productInCategory('Partner');

        $this->withinTenant($this->tenant, fn () => $partner['variant']->inventories()
            ->where('store_id', $this->store->getKey())
            ->update(['quantity' => 0]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_a_stale_recommendation_fingerprint_is_refused_after_the_catalogue_changes(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $this->productInCategory('Partner');

        $fingerprint = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ]))
            ->assertOk()
            ->json('data.catalog_fingerprint');

        $this->withinTenant($this->tenant, fn () => $anchor['variant']->update(['price' => 99]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.bundles.recommendations.index', [
                'product_id' => $anchor['product']->getKey(),
                'store_id' => $this->store->getKey(),
                'expected_catalog_fingerprint' => $fingerprint,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_recommendations');
    }

    public function test_the_ai_explanation_is_grounded_in_server_recomputed_evidence(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->productInCategory('Partner');
        $this->orderWith([$anchor, $partner]);

        $this->fakeModel(new AiCompletion(
            text: '{}',
            structured: ['explanation' => 'Partner appeared with Anchor in 1 measured order. This is order history, not a conversion rate.'],
            model: 'fake-model',
            inputTokens: 80,
            outputTokens: 20,
            totalTokens: 100,
        ));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.recommendations.explain'), [
                'product_id' => $anchor['product']->getKey(),
                'partner_product_id' => $partner['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.evidence.basis', 'purchased_together')
            ->assertJsonPath('data.evidence.order_count', 1)
            ->assertJsonPath('data.explanation', 'Partner appeared with Anchor in 1 measured order. This is order history, not a conversion rate.')
            ->assertJsonPath('data.usage.model', 'fake-model');
    }

    public function test_an_explanation_without_measured_or_catalogue_evidence_is_refused(): void
    {
        $anchor = $this->productInCategory('Anchor');
        $partner = $this->withinTenant($this->tenant, fn (): array => $this->stockedProduct($this->tenant, $this->store));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.bundles.recommendations.explain'), [
                'product_id' => $anchor['product']->getKey(),
                'partner_product_id' => $partner['product']->getKey(),
                'store_id' => $this->store->getKey(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_bundle_evidence');
    }

    /**
     * @return array{product: Product, variant: ProductVariant, store: Store}
     */
    private function productInCategory(string $name): array
    {
        $product = $this->stockedProduct($this->tenant, $this->store);

        $this->withinTenant($this->tenant, fn () => $product['product']->update([
            'name' => $name,
            'category_id' => $this->category->getKey(),
        ]));

        return $product;
    }

    /**
     * @param  array{product: Product, variant: ProductVariant, store: Store}  $anchor
     * @param  array{product: Product, variant: ProductVariant, store: Store}  $partner
     */
    private function bundle(array $anchor, array $partner): Bundle
    {
        return $this->withinTenant($this->tenant, function () use ($anchor, $partner): Bundle {
            $bundle = Bundle::factory()->active()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'discount_value' => 10,
            ]);

            $this->app->make(BundleService::class)->update($bundle, [
                'items' => [
                    ['product_id' => $anchor['product']->getKey(), 'product_variant_id' => $anchor['variant']->getKey()],
                    ['product_id' => $partner['product']->getKey(), 'product_variant_id' => $partner['variant']->getKey()],
                ],
            ]);

            return $bundle->refresh();
        });
    }

    /**
     * @param  list<array{product: Product, variant: ProductVariant, store: Store}>  $products
     */
    private function orderWith(array $products, OrderStatus $status = OrderStatus::Pending): Order
    {
        return $this->withinTenant($this->tenant, function () use ($products, $status): Order {
            $order = Order::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'store_id' => $this->store->getKey(),
                'status' => $status,
                'placed_at' => now()->subDays(3),
                'currency' => 'USD',
            ]);

            foreach ($products as $product) {
                $order->items()->create([
                    'tenant_id' => $this->tenant->getKey(),
                    'product_id' => $product['product']->getKey(),
                    'product_variant_id' => $product['variant']->getKey(),
                    'name' => $product['product']->name,
                    'sku' => $product['variant']->sku,
                    'unit_price' => 10,
                    'quantity' => 1,
                    'line_total' => 10,
                ]);
            }

            return $order;
        });
    }

    private function fakeModel(AiCompletion $completion): void
    {
        $model = new FakeLanguageModel($completion);

        $this->app->instance(LanguageModel::class, $model);
        $this->app->instance(AiProviderFactory::class, new FakeAiProviderFactory($model));

        config()->set('ai.enabled', true);
        config()->set('ai.provider', 'fake');
        config()->set('ai.providers.fake', [
            'driver' => 'fake',
            'base_url' => '',
            'api_key' => null,
            'path' => '',
            'model' => 'fake',
            'transport' => [],
        ]);
        config()->set('ai.features.bundle_explanation.enabled', true);
        config()->set('ai.features.bundle_explanation.daily_per_user', 100);
        config()->set('ai.features.bundle_explanation.daily_per_tenant', 100);
    }
}
