<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TranslationOrigin;
use App\Enums\TranslationStatus;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\HelpSearchLog;
use App\Models\HelpTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help Center search.
 *
 * Search is bilingual by construction — the canonical columns, the legacy
 * Arabic columns, tag names and approved ledger translations all match — and
 * it records what was searched for so the platform can see what readers
 * cannot find. These tests pin the matching surface, the logging and the
 * escaping that keeps a typed wildcard from acting like one.
 */
class HelpCenterSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_titles_bodies_and_tag_names(): void
    {
        $payments = HelpTag::factory()->create(['name' => 'Payments', 'slug' => 'payments']);
        $article = HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'slug' => 'accepting-payments',
            'excerpt' => 'Cash, transfer and online providers.',
            'body' => '<p>Choose the methods that suit your store.</p>',
        ]);
        $article->tags()->attach($payments->getKey());

        HelpArticle::factory()->create([
            'title' => 'Managing orders',
            'slug' => 'managing-orders',
            'body' => '<p>The order pipeline.</p>',
        ]);

        foreach (['Accepting', 'transfer', 'online providers', 'Payments'] as $term) {
            $this->getJson('/api/v1/public/help/search?q='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data.articles')
                ->assertJsonPath('data.articles.0.slug', 'accepting-payments');
        }
    }

    public function test_search_matches_the_arabic_columns(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'title_ar' => 'استلام المدفوعات',
            'slug' => 'accepting-payments',
            'body_ar' => '<p>اختر طرق الدفع المناسبة لمتجرك.</p>',
        ]);

        $this->getJson('/api/v1/public/help/search?q='.urlencode('المدفوعات'))
            ->assertOk()
            ->assertJsonCount(1, 'data.articles');
    }

    public function test_search_matches_approved_ledger_translations(): void
    {
        $article = HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'slug' => 'accepting-payments',
        ]);

        $article->translations()->create([
            'locale' => 'fr',
            'field' => 'title',
            'source_locale' => 'en',
            'source_text' => 'Accepting payments',
            'source_hash' => hash('sha256', 'Accepting payments'),
            'translated_text' => 'Accepter les paiements',
            'published_text' => 'Accepter les paiements',
            'status' => TranslationStatus::Approved,
            'origin' => TranslationOrigin::Manual,
        ]);

        $this->getJson('/api/v1/public/help/search?q='.urlencode('paiements'))
            ->assertOk()
            ->assertJsonCount(1, 'data.articles')
            ->assertJsonPath('data.articles.0.slug', 'accepting-payments');
    }

    public function test_a_search_is_logged_with_its_results_count(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'slug' => 'accepting-payments',
        ]);

        $this->getJson('/api/v1/public/help/search?q=payments')
            ->assertOk()
            ->assertJsonCount(1, 'data.articles');

        $this->assertDatabaseHas('help_search_logs', [
            'term' => 'payments',
            'results_count' => 1,
        ]);
    }

    public function test_a_zero_result_search_is_logged_as_such(): void
    {
        $this->getJson('/api/v1/public/help/search?q=quantum+accounting')
            ->assertOk()
            ->assertJsonCount(0, 'data.articles');

        $this->assertDatabaseHas('help_search_logs', [
            'term' => 'quantum accounting',
            'results_count' => 0,
        ]);
    }

    public function test_an_empty_term_returns_an_empty_result_without_logging(): void
    {
        $this->getJson('/api/v1/public/help/search')
            ->assertOk()
            ->assertJsonPath('data.query', '')
            ->assertJsonCount(0, 'data.articles');

        $this->assertSame(0, HelpSearchLog::query()->count());
    }

    public function test_like_wildcards_in_a_term_do_not_match_everything(): void
    {
        HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'slug' => 'accepting-payments',
        ]);

        $this->getJson('/api/v1/public/help/search?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data.articles');

        $this->getJson('/api/v1/public/help/search?q=_')
            ->assertOk()
            ->assertJsonCount(0, 'data.articles');
    }

    public function test_search_also_returns_matching_categories(): void
    {
        $category = HelpCategory::factory()->create([
            'name' => 'Payments',
            'slug' => 'payments-category',
            'description' => 'How money reaches you.',
        ]);

        $this->getJson('/api/v1/public/help/search?q=Payments')
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.slug', $category->slug);
    }
}
