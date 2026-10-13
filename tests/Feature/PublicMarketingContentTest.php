<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\PricingPlan;
use Database\Seeders\MarketingContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unauthenticated marketing endpoints: features, pricing and FAQ.
 *
 * These are the answers a visitor relies on before trusting the product, so
 * two properties matter more than anything else: the endpoints work with no
 * session at all, and they never present a plan as priced when nobody has
 * decided the price yet.
 */
class PublicMarketingContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_features_lists_capability_with_a_machine_readable_status_and_no_auth(): void
    {
        $response = $this->getJson('/api/v1/public/features')->assertOk();

        $features = $response->json('data.features');
        $this->assertNotEmpty($features);

        foreach ($features as $feature) {
            $this->assertArrayHasKey('slug', $feature);
            $this->assertArrayHasKey('name', $feature);
            $this->assertArrayHasKey('description', $feature);
            $this->assertContains($feature['status'], ['available', 'coming_soon']);
        }

        $slugs = array_column($features, 'slug');
        $this->assertContains('products', $slugs);
        $this->assertContains('inventory', $slugs);
        $this->assertContains('orders', $slugs);
    }

    public function test_pricing_returns_only_active_plans_in_display_order(): void
    {
        PricingPlan::factory()->create([
            'slug' => 'second', 'name' => 'Second', 'price' => 20.00, 'sort_order' => 10,
        ]);
        PricingPlan::factory()->create([
            'slug' => 'first', 'name' => 'First', 'price' => 10.00, 'sort_order' => 0,
        ]);
        PricingPlan::factory()->inactive()->create([
            'slug' => 'hidden', 'name' => 'Hidden', 'price' => 99.00, 'sort_order' => 5,
        ]);

        $plans = $this->getJson('/api/v1/public/pricing')
            ->assertOk()
            ->json('data');

        $this->assertSame(['first', 'second'], array_column($plans, 'slug'));
    }

    public function test_an_undecided_price_is_published_as_null_never_as_zero(): void
    {
        PricingPlan::factory()->create([
            'slug' => 'free', 'name' => 'Free', 'price' => 0.00, 'sort_order' => 0,
        ]);
        PricingPlan::factory()->comingSoon()->create([
            'slug' => 'growth', 'name' => 'Growth', 'sort_order' => 10, 'highlighted' => true,
        ]);

        $plans = $this->getJson('/api/v1/public/pricing')->assertOk()->json('data');

        $bySlug = collect($plans)->keyBy('slug');

        // $0.00 and "not decided yet" are different facts and must stay
        // different through the API.
        $this->assertEquals(0.0, $bySlug->get('free')['price']);
        $this->assertNull($bySlug->get('growth')['price']);
        $this->assertTrue($bySlug->get('growth')['highlighted']);
    }

    public function test_a_published_plan_is_not_advertised_as_purchasable_without_a_provider(): void
    {
        PricingPlan::factory()->create([
            'slug' => 'free', 'name' => 'Free', 'price' => 0.00, 'sort_order' => 0,
        ]);
        PricingPlan::factory()->create([
            'slug' => 'pro', 'name' => 'Pro', 'price' => 49.00, 'sort_order' => 10,
        ]);
        PricingPlan::factory()->comingSoon()->create([
            'slug' => 'growth', 'name' => 'Growth', 'sort_order' => 5,
        ]);

        $bySlug = collect($this->getJson('/api/v1/public/pricing')->assertOk()->json('data'))->keyBy('slug');

        // The Free plan is real: registration completes it.
        $this->assertTrue($bySlug->get('free')['purchase_available']);

        // A coming-soon plan and a published-but-providerless paid plan are
        // both unpurchasable — publication is not the same as being on sale.
        $this->assertFalse($bySlug->get('growth')['purchase_available']);
        $this->assertFalse($bySlug->get('pro')['purchase_available']);
    }

    public function test_a_paid_plan_becomes_purchasable_only_once_billing_is_enabled(): void
    {
        config([
            'dukavo.billing.provider' => 'stripe',
            'dukavo.billing.checkout_enabled' => true,
        ]);

        PricingPlan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'price' => 49.00]);

        $plan = $this->getJson('/api/v1/public/pricing')->assertOk()->json('data.0');

        $this->assertTrue($plan['purchase_available']);
    }

    public function test_the_public_reads_share_one_envelope(): void
    {
        PricingPlan::factory()->create();
        FaqEntry::factory()->create();

        foreach (['features', 'pricing', 'faq'] as $endpoint) {
            $this->getJson("/api/v1/public/{$endpoint}")
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonStructure(['data']);
        }
    }

    public function test_pricing_requires_no_authentication(): void
    {
        PricingPlan::factory()->create(['slug' => 'open-door', 'price' => 5.00]);

        // A bare GET, no session, no bearer token: the public reads carry the
        // same `success` envelope as every other Dukavo response.
        $this->getJson('/api/v1/public/pricing')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['slug', 'price']]]);
    }

    public function test_faq_returns_active_entries_in_display_order(): void
    {
        FaqEntry::factory()->create(['question' => 'Second?', 'sort_order' => 10]);
        FaqEntry::factory()->create(['question' => 'First?', 'sort_order' => 0]);
        FaqEntry::factory()->inactive()->create(['question' => 'Hidden?', 'sort_order' => 5]);

        $questions = $this->getJson('/api/v1/public/faq')
            ->assertOk()
            ->json('data');

        $this->assertSame(['First?', 'Second?'], array_column($questions, 'question'));
    }

    public function test_the_seeded_marketing_content_is_served_verbatim(): void
    {
        $this->seed(MarketingContentSeeder::class);

        $plans = $this->getJson('/api/v1/public/pricing')->assertOk()->json('data');
        $this->assertSame(['free', 'growth', 'pro'], array_column($plans, 'slug'));
        $this->assertEquals(0.0, $plans[0]['price']);
        $this->assertNull($plans[1]['price']);

        $questions = $this->getJson('/api/v1/public/faq')->assertOk()->json('data');
        $this->assertCount(6, $questions);
    }
}
