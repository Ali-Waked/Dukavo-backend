<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HelpArticleStatus;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use App\Services\Translation\TranslationEntityRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Platform administration of the Help Center.
 *
 * Articles are Dukavo's own content, so the access rules match the FAQ and
 * newsletter administration: a platform operator may author them and a
 * tenant member may not. These tests also pin the editorial actions — a
 * publish stamps `published_at`, an archive removes the article from
 * readers without deleting it, and a category that still holds articles
 * refuses deletion.
 */
class HelpCenterAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(): User
    {
        return $this->platformUser('super-admin');
    }

    public function test_help_administration_requires_authentication(): void
    {
        $this->getJson('/api/v1/platform/help/articles')->assertStatus(401);
        $this->getJson('/api/v1/platform/help/categories')->assertStatus(401);
    }

    public function test_a_tenant_merchant_cannot_reach_the_help_endpoints(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/help/articles')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_operator_can_create_an_article_with_a_sanitised_body(): void
    {
        $category = HelpCategory::factory()->create(['slug' => 'payments-help']);

        $response = $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles', [
                'category_id' => $category->getKey(),
                'title' => 'Accepting payments',
                'slug' => 'accepting-payments',
                'excerpt' => 'How money reaches you.',
                'body' => '<p>Choose your methods.</p><script>alert("no")</script>',
                'status' => 'published',
                'visibility' => 'public',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'accepting-payments');

        $article = HelpArticle::query()->where('slug', 'accepting-payments')->sole();

        // A script is not prose: the sanitizer drops it whole, content included.
        $this->assertSame('<p>Choose your methods.</p>', $article->body);
        $this->assertSame(HelpArticleStatus::Published, $article->status);
        $this->assertNotNull($article->published_at);
        $this->assertSame($response->json('data.author.id'), $article->author_id);
    }

    public function test_publish_stamps_published_at_once_and_unpublish_clears_it(): void
    {
        $article = HelpArticle::factory()->draft()->create(['slug' => 'ready-to-ship']);

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles/'.$article->getKey().'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $firstStamp = $article->refresh()->published_at;

        $this->assertNotNull($firstStamp);

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles/'.$article->getKey().'/unpublish')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertNull($article->refresh()->published_at);

        // Re-publishing stamps the date afresh, not the original one.
        $this->travel(1)->hours();

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles/'.$article->getKey().'/publish')
            ->assertOk();

        $this->assertTrue($article->refresh()->published_at->gt($firstStamp));
    }

    public function test_archive_removes_an_article_from_readers_and_restore_returns_a_draft(): void
    {
        $article = HelpArticle::factory()->create(['slug' => 'now-retired']);

        $this->getJson('/api/v1/public/help/articles/now-retired')->assertOk();

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles/'.$article->getKey().'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->getJson('/api/v1/public/help/articles/now-retired')->assertNotFound();
        $this->assertDatabaseHas('help_articles', ['slug' => 'now-retired']);

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/articles/'.$article->getKey().'/restore')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // A restored article is a draft: it does not go straight back to the
        // public surface.
        $this->getJson('/api/v1/public/help/articles/now-retired')->assertNotFound();
    }

    public function test_a_category_with_articles_cannot_be_deleted(): void
    {
        $category = HelpCategory::factory()->create(['slug' => 'occupied']);
        HelpArticle::factory()->create(['category_id' => $category->getKey()]);

        $this->actingAs($this->operator(), 'sanctum')
            ->deleteJson('/api/v1/platform/help/categories/'.$category->getKey())
            ->assertStatus(409);

        $this->assertDatabaseHas('help_categories', ['slug' => 'occupied']);
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = HelpCategory::factory()->create(['slug' => 'empty']);

        $this->actingAs($this->operator(), 'sanctum')
            ->deleteJson('/api/v1/platform/help/categories/'.$category->getKey())
            ->assertNoContent();

        $this->assertDatabaseMissing('help_categories', ['slug' => 'empty']);
    }

    public function test_categories_can_be_reordered(): void
    {
        $first = HelpCategory::factory()->create(['slug' => 'first', 'sort_order' => 0]);
        $second = HelpCategory::factory()->create(['slug' => 'second', 'sort_order' => 1]);

        $this->actingAs($this->operator(), 'sanctum')
            ->postJson('/api/v1/platform/help/categories/reorder', [
                'ids' => [$second->getKey(), $first->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'second')
            ->assertJsonPath('data.1.slug', 'first');

        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(0, $second->refresh()->sort_order);
    }

    public function test_slug_availability_reports_a_taken_slug(): void
    {
        HelpArticle::factory()->create(['slug' => 'taken']);

        $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/platform/help/articles/slug-availability?slug=taken')
            ->assertOk()
            ->assertJsonPath('data.available', false);

        $this->actingAs($this->operator(), 'sanctum')
            ->getJson('/api/v1/platform/help/articles/slug-availability?slug=free')
            ->assertOk()
            ->assertJsonPath('data.available', true);
    }

    public function test_tags_can_be_managed_and_counted(): void
    {
        $operator = $this->operator();

        $tag = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/platform/help/tags', ['name' => 'Payments'])
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'payments')
            ->json('data.id');

        $article = HelpArticle::factory()->create();
        $article->tags()->attach($tag);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/platform/help/tags')
            ->assertOk()
            ->assertJsonPath('data.0.article_count', 1);

        $this->actingAs($operator, 'sanctum')
            ->patchJson('/api/v1/platform/help/tags/'.$tag, ['name' => 'Payment methods'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Payment methods');

        // Deleting a tag removes the link, never the article.
        $this->actingAs($operator, 'sanctum')
            ->deleteJson('/api/v1/platform/help/tags/'.$tag)
            ->assertNoContent();

        $this->assertSame(0, $article->tags()->count());
        $this->assertDatabaseHas('help_articles', ['id' => $article->getKey()]);
    }

    public function test_the_translation_registry_exposes_the_help_entities(): void
    {
        $registry = app(TranslationEntityRegistry::class);

        $article = $registry->get('help_article');
        $category = $registry->get('help_category');
        $tag = $registry->get('help_tag');

        $this->assertNotNull($article);
        $this->assertNotNull($category);
        $this->assertNotNull($tag);
        $this->assertFalse($article->tenantScoped);
        $this->assertFalse($category->tenantScoped);
        $this->assertFalse($tag->tenantScoped);
        $this->assertNotNull($article->field('body'));
        $this->assertNotNull($article->field('slug'));
        $this->assertNotNull($category->field('name'));
        $this->assertNotNull($tag->field('name'));
    }
}
