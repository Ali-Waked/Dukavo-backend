<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\HelpArticleVisibility;
use App\Models\HelpArticle;
use App\Models\HelpArticleFeedback;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "was this helpful?" vote on a Help Center article.
 *
 * A vote is recorded once per person — per account when signed in, per salted
 * IP fingerprint when anonymous — and the article's counters move with the
 * rows they summarise. The raw address never reaches storage, and a
 * restricted article refuses feedback exactly like it refuses reads.
 */
class HelpFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function article(): HelpArticle
    {
        return HelpArticle::factory()->create([
            'title' => 'Accepting payments',
            'slug' => 'accepting-payments',
        ]);
    }

    public function test_a_guest_vote_is_recorded_and_deduplicated_by_ip(): void
    {
        $article = $this->article();

        $this->postJson('/api/v1/public/help/articles/accepting-payments/feedback', [
            'helpful' => true,
            'comment' => 'Clear and short.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('help_article_feedback', [
            'article_id' => $article->getKey(),
            'user_id' => null,
            'helpful' => true,
            'comment' => 'Clear and short.',
        ]);

        // The same address voting again is told it already answered, and no
        // second row appears.
        $this->postJson('/api/v1/public/help/articles/accepting-payments/feedback', [
            'helpful' => false,
        ])->assertOk();

        $this->assertSame(1, HelpArticleFeedback::query()->count());
        $this->assertSame(1, $article->refresh()->helpful_count);
        $this->assertSame(0, $article->refresh()->unhelpful_count);
    }

    public function test_a_signed_in_vote_is_deduplicated_by_account(): void
    {
        $article = $this->article();
        $reader = User::factory()->create();

        $this->actingAs($reader, 'sanctum')
            ->postJson('/api/v1/help/articles/accepting-payments/feedback', ['helpful' => true])
            ->assertStatus(201);

        // A different IP cannot stack a second vote onto the same account.
        $this->actingAs($reader, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->postJson('/api/v1/help/articles/accepting-payments/feedback', ['helpful' => false])
            ->assertOk();

        $this->assertSame(1, HelpArticleFeedback::query()->count());
        $this->assertSame(1, $article->refresh()->helpful_count);
    }

    public function test_feedback_on_a_restricted_article_is_a_404(): void
    {
        HelpArticle::factory()->create([
            'slug' => 'merchant-only',
            'visibility' => HelpArticleVisibility::Merchants,
        ]);

        $this->postJson('/api/v1/public/help/articles/merchant-only/feedback', [
            'helpful' => true,
        ])->assertNotFound();

        $this->assertSame(0, HelpArticleFeedback::query()->count());
    }

    public function test_the_anonymous_ip_is_never_stored_raw(): void
    {
        $this->article();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->postJson('/api/v1/public/help/articles/accepting-payments/feedback', ['helpful' => true])
            ->assertStatus(201);

        $row = HelpArticleFeedback::query()->sole();

        $this->assertNotSame('198.51.100.23', $row->ip_hash);
        $this->assertSame(
            hash('sha256', '198.51.100.23'.config('app.key')),
            $row->ip_hash,
        );
    }

    public function test_deleting_feedback_reconciles_the_counters(): void
    {
        $article = $this->article();
        $operator = $this->platformUser('super-admin');

        $feedback = HelpArticleFeedback::query()->create([
            'article_id' => $article->getKey(),
            'user_id' => User::factory()->create()->getKey(),
            'ip_hash' => null,
            'helpful' => true,
        ]);

        $article->forceFill(['helpful_count' => 1])->save();

        $this->actingAs($operator, 'sanctum')
            ->deleteJson('/api/v1/platform/help/feedback/'.$feedback->getKey())
            ->assertNoContent();

        $this->assertSame(0, $article->refresh()->helpful_count);
        $this->assertSame(0, HelpArticleFeedback::query()->count());
    }

    public function test_the_feedback_review_list_needs_a_platform_operator(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->getJson('/api/v1/platform/help/feedback')->assertStatus(401);

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/help/feedback')
            ->assertStatus(403);
    }
}
