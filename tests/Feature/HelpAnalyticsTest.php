<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpSearchLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The analytics the platform team reads about the Help Center.
 *
 * Every figure on these screens is an aggregate over the real article,
 * feedback and search tables, so the tests hold them to that: status and
 * visibility totals include drafts, reader numbers only count published
 * articles, search reports respect their window, and the "least helpful"
 * queue ignores articles nobody has voted on yet.
 */
class HelpAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_help_analytics_requires_a_platform_operator(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->getJson('/api/v1/platform/help/analytics/overview')->assertStatus(401);

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/overview')
            ->assertStatus(403);
    }

    public function test_the_overview_counts_articles_by_status_and_visibility(): void
    {
        HelpArticle::factory()->draft()->create();
        HelpArticle::factory()->inReview()->create();
        HelpArticle::factory()->archived()->create();
        HelpArticle::factory()->count(2)->create();

        HelpArticle::factory()->visibility(HelpArticleVisibility::Merchants)->create();
        HelpArticle::factory()->visibility(HelpArticleVisibility::Platform)->create();

        $totals = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/overview')
            ->assertOk()
            ->json('data.totals');

        // Seven articles exist: one draft, one in review, one archived, and
        // four published (two public, one merchant-only, one platform-only).
        $this->assertSame(7, $totals['articles']);
        $this->assertSame(4, $totals['published_articles']);
        $this->assertSame(1, $totals['by_status']['draft']);
        $this->assertSame(1, $totals['by_status']['in_review']);
        $this->assertSame(4, $totals['by_status']['published']);
        $this->assertSame(1, $totals['by_status']['archived']);
        $this->assertSame(5, $totals['by_visibility']['public']);
        $this->assertSame(1, $totals['by_visibility']['merchants']);
        $this->assertSame(1, $totals['by_visibility']['platform']);
    }

    public function test_the_overview_lists_categories_with_no_articles(): void
    {
        $occupied = HelpCategory::factory()->create(['slug' => 'occupied', 'sort_order' => 0]);
        HelpCategory::factory()->create(['slug' => 'barren', 'sort_order' => 1]);
        HelpCategory::factory()->create(['slug' => 'also-barren', 'sort_order' => 2]);
        HelpArticle::factory()->create(['category_id' => $occupied->getKey()]);

        $empty = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/overview')
            ->assertOk()
            ->json('data.empty_categories');

        $this->assertSame(['barren', 'also-barren'], array_column($empty, 'slug'));
        $this->assertSame(3, $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/overview')
            ->json('data.totals.categories'));
    }

    public function test_reader_numbers_only_count_published_articles(): void
    {
        $read = HelpArticle::factory()->create([
            'view_count' => 100,
            'helpful_count' => 3,
            'unhelpful_count' => 1,
        ]);

        HelpArticle::factory()->archived()->create([
            'view_count' => 999,
            'helpful_count' => 99,
            'unhelpful_count' => 99,
        ]);

        HelpSearchLog::factory()->create();

        $totals = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/overview')
            ->assertOk()
            ->json('data.totals');

        $this->assertSame(100, $totals['published_views']);
        $this->assertSame(3, $totals['helpful_votes']);
        $this->assertSame(1, $totals['unhelpful_votes']);
        $this->assertSame(0.75, $totals['helpfulness_ratio']);
        $this->assertSame(1, $totals['searches_last_window']);

        // The archived article stays out of the maintenance list of reader
        // favourites too.
        $mostRead = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/helpfulness')
            ->assertOk()
            ->json('data.most_read');

        $this->assertSame($read->slug, $mostRead[0]['slug']);
        $this->assertSame(100, $mostRead[0]['view_count']);
        $this->assertSame(0.75, $mostRead[0]['helpfulness']);
    }

    public function test_search_reports_are_aggregated_by_term_and_windowed(): void
    {
        HelpSearchLog::factory()->count(3)->create(['term' => 'payments', 'results_count' => 2]);
        HelpSearchLog::factory()->create(['term' => 'refunds', 'results_count' => 0]);
        HelpSearchLog::factory()->create([
            'term' => 'legacy question',
            'results_count' => 5,
            'created_at' => now()->subDays(45),
        ]);

        $operator = $this->platformUser('super-admin');

        $report = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/searches')
            ->assertOk()
            ->json('data');

        $this->assertSame(30, $report['days']);

        $payments = collect($report['popular'])->firstWhere('term', 'payments');
        $this->assertNotNull($payments);
        $this->assertSame(3, $payments['searches']);
        $this->assertSame(6, $payments['results']);

        $this->assertNull(collect($report['popular'])->firstWhere('term', 'legacy question'));
        $this->assertSame(
            ['refunds'],
            array_column($report['zero_results'], 'term'),
        );

        // Widening the window brings the old term back into view.
        $wider = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/searches?days=60')
            ->assertOk()
            ->json('data.popular');

        $this->assertNotNull(collect($wider)->firstWhere('term', 'legacy question'));
    }

    public function test_least_helpful_ignores_articles_with_fewer_than_three_votes(): void
    {
        HelpArticle::factory()->create([
            'slug' => 'clear-favourite',
            'view_count' => 10,
            'helpful_count' => 9,
            'unhelpful_count' => 1,
        ]);

        HelpArticle::factory()->create([
            'slug' => 'needs-a-rewrite',
            'view_count' => 10,
            'helpful_count' => 1,
            'unhelpful_count' => 4,
        ]);

        HelpArticle::factory()->create([
            'slug' => 'one-complaint',
            'view_count' => 10,
            'helpful_count' => 0,
            'unhelpful_count' => 2,
        ]);

        $least = $this->actingAs($this->platformUser('super-admin'), 'sanctum')
            ->getJson('/api/v1/platform/help/analytics/helpfulness')
            ->assertOk()
            ->json('data.least_helpful');

        $this->assertSame('needs-a-rewrite', $least[0]['slug']);
        $this->assertSame('clear-favourite', $least[1]['slug']);
        $this->assertNotContains('one-complaint', array_column($least, 'slug'));
    }
}
