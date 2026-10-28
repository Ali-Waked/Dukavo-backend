<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\RoleScope;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Exceptions\AiUnavailable;
use App\Services\Ai\Providers\AiProviderFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * The tenant AI endpoints: reply drafting and the workspace briefing.
 *
 * The properties that matter are asserted here rather than the wording of any
 * output: authentication, the `ai.use` permission, tenant isolation, the
 * feature/limit switches, graceful failure when the provider is unavailable, and
 * the promise that a draft changes nothing until a human acts on it.
 */
class AiAssistantEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');

        config()->set('ai.enabled', true);
        config()->set('ai.features.reply.enabled', true);
        config()->set('ai.features.insights.enabled', true);
        config()->set('ai.features.reply.daily_per_user', 100);
        config()->set('ai.features.reply.daily_per_tenant', 100);
    }

    private function fakeModel(?AiCompletion $completion = null, ?\Throwable $throw = null): void
    {
        $model = new FakeLanguageModel($completion, $throw);

        $this->app->instance(LanguageModel::class, $model);
        $this->app->instance(AiProviderFactory::class, new FakeAiProviderFactory($model));

        config()->set('ai.provider', 'fake');
        config()->set('ai.providers.fake', [
            'driver' => 'fake',
            'base_url' => '',
            'api_key' => null,
            'path' => '',
            'model' => 'fake',
            'transport' => [],
        ]);
    }

    private function conversationWithMessage(): Conversation
    {
        return $this->withinTenant($this->tenant, function (): Conversation {
            $customer = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

            $conversation = Conversation::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'customer_id' => $customer->getKey(),
            ]);

            $conversation->messages()->create([
                'tenant_id' => $this->tenant->getKey(),
                'direction' => MessageDirection::Inbound,
                'type' => 'text',
                'body' => 'Where is my order?',
                'delivery_status' => 'received',
                'sent_at' => now(),
            ]);

            return $conversation->refresh();
        });
    }

    public function test_the_reply_draft_requires_authentication(): void
    {
        $conversation = $this->conversationWithMessage();

        $this->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft")
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_a_merchant_receives_a_draft_without_anything_being_sent(): void
    {
        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'reply' => 'Thanks for reaching out, we are looking into it now.',
                'tone' => 'friendly',
                'suggestions' => ['Ask for the order number'],
            ],
            model: 'grok-4.7',
        ));

        $conversation = $this->conversationWithMessage();

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft", [
                'instruction' => 'Be reassuring',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.draft', 'Thanks for reaching out, we are looking into it now.')
            ->assertJsonPath('data.tone', 'friendly')
            ->assertJsonPath('data.suggestions.0', 'Ask for the order number');

        // The draft is never persisted: the thread still holds only the inbound
        // message the customer sent.
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()->count());
        $this->assertSame('Where is my order?', ConversationMessage::query()->withoutGlobalScopes()->first()->body);
    }

    public function test_staff_can_use_ai_by_default(): void
    {
        $this->fakeModel();
        $staff = $this->memberOf($this->tenant, 'staff');
        $conversation = $this->conversationWithMessage();

        $this->actingAs($staff, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft")
            ->assertOk();
    }

    public function test_a_role_without_the_ai_permission_is_refused(): void
    {
        $this->fakeModel();
        $conversation = $this->conversationWithMessage();

        $role = Role::query()->create([
            'name' => 'Watcher',
            'slug' => 'watcher',
            'scope' => RoleScope::Tenant,
            'description' => 'Read-only observation.',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['dashboard.view', 'conversations.view'])->pluck('id'),
        );

        $user = User::factory()->create();
        $user->tenants()->attach($this->tenant->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $this->tenant->getKey(),
        ]);

        $this->actingAs($user->fresh(), 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_provider_failure_is_reported_as_unavailable(): void
    {
        $this->fakeModel(throw: AiUnavailable::provider('timeout'));
        $conversation = $this->conversationWithMessage();

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft")
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ai_unavailable');
    }

    public function test_ai_is_unavailable_when_the_master_switch_is_off(): void
    {
        config()->set('ai.enabled', false);
        $conversation = $this->conversationWithMessage();

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft")
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_feature_disabled');
    }

    public function test_the_daily_limit_is_enforced(): void
    {
        config()->set('ai.features.reply.daily_per_user', 1);
        $this->fakeModel();
        $conversation = $this->conversationWithMessage();
        $endpoint = "/api/v1/tenant/conversations/{$conversation->getKey()}/ai-reply-draft";

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($endpoint)
            ->assertOk();

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson($endpoint)
            ->assertStatus(429)
            ->assertJsonPath('code', 'ai_rate_limited');
    }

    public function test_tenant_insights_return_a_grounded_briefing(): void
    {
        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'summary' => 'No orders have been placed yet.',
                'highlights' => [['title' => 'Quiet day', 'detail' => 'Zero orders today.', 'severity' => 'neutral']],
                'recommendations' => [['title' => 'Promote a product', 'detail' => 'Share a catalogue link.']],
                'data_gaps' => ['No orders today'],
            ],
            model: 'grok-4.7',
        ));

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/ai/insights')
            ->assertOk()
            ->assertJsonPath('data.summary', 'No orders have been placed yet.')
            ->assertJsonPath('data.highlights.0.severity', 'neutral')
            ->assertJsonPath('data.data_gaps.0', 'No orders today');
    }

    public function test_tenant_insights_reject_a_foreign_store(): void
    {
        $other = $this->tenantWithStore();
        $otherMerchant = $this->memberOf($other, 'merchant');
        $this->fakeModel();

        $foreignStore = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->actingAs($otherMerchant, 'sanctum')
            ->withHeader('X-Tenant', $other->slug)
            ->getJson('/api/v1/tenant/ai/insights?store_id='.$foreignStore->getKey())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store_id');
    }
}
