<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reader visibility matrix of the Help Center.
 *
 * Visibility is computed server-side from who is asking — never from the
 * request — and the tiers are cumulative. These tests pin the whole matrix
 * on the list, the detail and the search, and the rule that a restricted
 * article answers 404 rather than 403, so a guessed URL learns nothing
 * about what exists.
 */
class HelpCenterVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * One published article per visibility tier, all in one category.
     *
     * @return array{public: HelpArticle, authenticated: HelpArticle, merchants: HelpArticle, platform: HelpArticle}
     */
    private function articlesAcrossTiers(): array
    {
        // One shared category, so the categories endpoint has a deterministic
        // first row whose article count is the only thing under test.
        $category = HelpCategory::factory()->create(['sort_order' => 0]);

        return [
            'public' => HelpArticle::factory()->for($category, 'category')->create([
                'title' => 'Public guide',
                'slug' => 'public-guide',
                'visibility' => HelpArticleVisibility::Public,
            ]),
            'authenticated' => HelpArticle::factory()->for($category, 'category')->create([
                'title' => 'Signed-in guide',
                'slug' => 'signed-in-guide',
                'visibility' => HelpArticleVisibility::Authenticated,
            ]),
            'merchants' => HelpArticle::factory()->for($category, 'category')->create([
                'title' => 'Merchant guide',
                'slug' => 'merchant-guide',
                'visibility' => HelpArticleVisibility::Merchants,
            ]),
            'platform' => HelpArticle::factory()->for($category, 'category')->create([
                'title' => 'Platform guide',
                'slug' => 'platform-guide',
                'visibility' => HelpArticleVisibility::Platform,
            ]),
        ];
    }

    public function test_a_guest_sees_only_public_articles(): void
    {
        $this->articlesAcrossTiers();

        $this->getJson('/api/v1/public/help/articles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Public guide');
    }

    public function test_a_restricted_article_is_a_404_for_everyone_below_its_tier(): void
    {
        $this->articlesAcrossTiers();

        $this->getJson('/api/v1/public/help/articles/merchant-guide')->assertNotFound();

        // The authenticated surface serves signed-in readers who hold no
        // workspace membership — the merchants tier stays closed to them.
        $reader = User::factory()->create();

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/help/articles/merchant-guide')
            ->assertNotFound();
    }

    public function test_an_authenticated_reader_gains_the_authenticated_tier(): void
    {
        $this->articlesAcrossTiers();

        $reader = User::factory()->create();

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/help/articles')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/help/articles/signed-in-guide')
            ->assertOk()
            ->assertJsonPath('data.title', 'Signed-in guide');
    }

    public function test_a_merchant_member_gains_the_merchants_tier(): void
    {
        $this->articlesAcrossTiers();

        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/help/articles')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/help/articles/merchant-guide')
            ->assertOk()
            ->assertJsonPath('data.title', 'Merchant guide');

        // The platform tier stays closed to merchants.
        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/help/articles/platform-guide')
            ->assertNotFound();
    }

    public function test_a_platform_operator_sees_every_tier_on_the_authenticated_surface(): void
    {
        $this->articlesAcrossTiers();

        $operator = $this->platformUser('super-admin');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/help/articles')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/help/articles/platform-guide')
            ->assertOk()
            ->assertJsonPath('data.title', 'Platform guide');
    }

    public function test_the_public_surface_never_serves_unpublished_articles(): void
    {
        HelpArticle::factory()->draft()->create(['slug' => 'still-writing']);
        HelpArticle::factory()->inReview()->create(['slug' => 'awaiting-review']);
        HelpArticle::factory()->archived()->create(['slug' => 'retired-guide']);

        foreach (['still-writing', 'awaiting-review', 'retired-guide'] as $slug) {
            $this->getJson('/api/v1/public/help/articles/'.$slug)->assertNotFound();
        }

        $this->getJson('/api/v1/public/help/articles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_category_article_counts_reflect_the_viewer(): void
    {
        $this->articlesAcrossTiers();

        $guestCounts = $this->getJson('/api/v1/public/help/categories')
            ->assertOk()
            ->json('data.0.article_count');

        $this->assertSame(1, $guestCounts);

        $operator = $this->platformUser('super-admin');

        $operatorCounts = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/help/categories')
            ->assertOk()
            ->json('data.0.article_count');

        $this->assertSame(4, $operatorCounts);
    }

    public function test_search_respects_visibility(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Payment reconciliation walkthrough',
            'slug' => 'payment-reconciliation',
            'visibility' => HelpArticleVisibility::Merchants,
        ]);

        $this->getJson('/api/v1/public/help/search?q=reconciliation')
            ->assertOk()
            ->assertJsonCount(0, 'data.articles');

        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/help/search?q=reconciliation')
            ->assertOk()
            ->assertJsonCount(1, 'data.articles');
    }
}
