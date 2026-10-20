<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * The platform AI endpoints: the operator briefing and newsletter content help.
 *
 * Both are Dukavo-level (they read across tenants or author Dukavo's own
 * marketing content), so access is asserted against platform roles and the
 * newsletter output is checked to be sanitised before it leaves the server.
 */
class AiPlatformEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config()->set('ai.enabled', true);
        config()->set('ai.features.content.enabled', true);
        config()->set('ai.features.insights.enabled', true);
    }

    private function fakeModel(?AiCompletion $completion = null, ?\Throwable $throw = null): void
    {
        $this->app->instance(LanguageModel::class, new FakeLanguageModel($completion, $throw));
    }

    public function test_a_tenant_merchant_cannot_reach_platform_ai(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/ai/insights')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_dukavo_admin_receives_platform_insights(): void
    {
        $admin = $this->platformUser('dukavo-admin');

        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'summary' => 'Two new tenants this period.',
                'highlights' => [['title' => 'Tenant growth', 'detail' => 'Two tenants joined.', 'severity' => 'positive']],
                'recommendations' => [['title' => 'Welcome them', 'detail' => 'Send an onboarding note.']],
                'data_gaps' => [],
            ],
            model: 'grok-4.7',
        ));

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/ai/insights?range=7d')
            ->assertOk()
            ->assertJsonPath('data.summary', 'Two new tenants this period.')
            ->assertJsonPath('data.highlights.0.severity', 'positive');
    }

    public function test_newsletter_content_is_sanitised_before_it_is_returned(): void
    {
        $admin = $this->platformUser('super-admin');

        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'title' => 'Autumn Product Update',
                'excerpt' => 'What is new this season.',
                'body' => '<p>Hello</p><script>alert(1)</script><a href="javascript:alert(1)">link</a><strong>Bold</strong>',
            ],
            model: 'grok-4.7',
        ));

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/ai/newsletter-content', [
                'action' => 'draft',
                'brief' => 'Autumn product updates',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Autumn Product Update');

        $body = (string) $response->json('data.body');

        $this->assertStringContainsString('<p>Hello</p>', $body);
        $this->assertStringContainsString('<strong>Bold</strong>', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
    }

    public function test_a_brief_is_required_to_draft_content(): void
    {
        $admin = $this->platformUser('super-admin');
        $this->fakeModel();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/ai/newsletter-content', ['action' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('brief');
    }

    public function test_an_unknown_action_is_rejected(): void
    {
        $admin = $this->platformUser('super-admin');
        $this->fakeModel();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/platform/ai/newsletter-content', ['action' => 'publish', 'brief' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }
}
