<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\RoleScope;
use App\Jobs\RunAiUseCase;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Features\FeatureRegistry;
use App\Services\Ai\Providers\AiProviderFactory;
use App\Services\Ai\Support\IdempotencyStore;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * The parts of the AI foundation that are not one specific feature: the
 * catalogue the interface reads, and the queued use-case path.
 *
 * The tests below assert the promises that make the foundation reusable — a
 * use case is only advertised when it can actually run, the queue restores the
 * tenant from its own payload rather than ambient state, a model-proposed order
 * number is only reported when the tenant really has it, and a repeated job is a
 * no-op.
 */
class AiFoundationTest extends TestCase
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
        config()->set('ai.features.summary.enabled', true);
        config()->set('ai.features.summary.daily_per_user', 100);
        config()->set('ai.features.summary.daily_per_tenant', 100);
    }

    private function fakeModel(?AiCompletion $completion = null, ?\Throwable $throw = null): FakeLanguageModel
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

        return $model;
    }

    private function conversationWithMessage(): Conversation
    {
        return $this->withinTenant($this->tenant, function (): Conversation {
            $customer = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);

            $conversation = Conversation::factory()->create([
                'tenant_id' => $this->tenant->getKey(),
                'customer_id' => $customer->getKey(),
                'subject' => 'Order question',
            ]);

            $conversation->messages()->create([
                'tenant_id' => $this->tenant->getKey(),
                'direction' => MessageDirection::Inbound,
                'type' => 'text',
                'body' => 'Where is my order ORD-123456?',
                'delivery_status' => 'received',
                'sent_at' => now(),
            ]);

            return $conversation->refresh();
        });
    }

    public function test_availability_requires_authentication(): void
    {
        $this->getJson('/api/v1/tenant/ai/availability')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_availability_lists_the_use_cases_an_actor_can_run(): void
    {
        $this->fakeModel();

        $response = $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/ai/availability')
            ->assertOk()
            ->assertJsonPath('success', true);

        $available = collect($response->json('data'))->keyBy('key');

        $this->assertTrue($available->get('summary')['enabled']);
        $this->assertTrue($available->get('summary')['queueable']);
        $this->assertTrue($available->get('reply')['enabled']);
        $this->assertFalse($available->get('reply')['queueable']);
    }

    public function test_availability_reports_a_use_case_as_unavailable_when_ai_is_off(): void
    {
        $this->fakeModel();
        config()->set('ai.enabled', false);

        $response = $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/ai/availability')
            ->assertOk();

        $summary = collect($response->json('data'))->firstWhere('key', 'summary');

        $this->assertFalse($summary['enabled']);
    }

    public function test_a_summary_request_queues_the_job_and_returns_immediately(): void
    {
        Queue::fake();
        $conversation = $this->conversationWithMessage();

        $this->actingAs($this->merchant, 'sanctum')
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-summary")
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.conversation_id', $conversation->getKey());

        Queue::assertPushed(RunAiUseCase::class);
    }

    public function test_a_role_without_the_ai_permission_cannot_queue_a_summary(): void
    {
        Queue::fake();
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
            ->postJson("/api/v1/tenant/conversations/{$conversation->getKey()}/ai-summary")
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_the_queued_summary_is_stored_on_the_conversation_and_keeps_only_real_order_numbers(): void
    {
        $conversation = $this->conversationWithMessage();

        $order = $this->withinTenant($this->tenant, fn (): Order => Order::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => Store::query()->where('tenant_id', $this->tenant->getKey())->firstOrFail()->getKey(),
            'order_number' => 'ORD-123456',
        ]));

        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'summary' => 'The customer is asking about delivery.',
                'intent' => 'order_status',
                'sentiment' => 'neutral',
                'needs_human_reply' => true,
                'suggested_next_step' => 'Check the order status and reply.',
                'referenced_order_numbers' => ['ORD-123456', 'ORD-999999'],
            ],
            model: 'fake-model',
        ));

        $job = new RunAiUseCase(
            'summary',
            ['conversation_id' => (int) $conversation->getKey(), 'user_id' => (int) $this->merchant->getKey()],
            (int) $this->tenant->getKey(),
        );

        $job->handle(
            $this->app->make(FeatureRegistry::class),
            $this->app->make(TenantContext::class),
            $this->app->make(IdempotencyStore::class),
        );

        $fresh = Conversation::query()->withoutGlobalScopes()->findOrFail($conversation->getKey());
        $summary = $fresh->meta['ai_summary'] ?? null;

        $this->assertIsArray($summary);
        $this->assertSame('The customer is asking about delivery.', $summary['summary']);
        $this->assertTrue($summary['needs_human_reply']);
        $this->assertSame([$order->order_number], $summary['referenced_orders']);
        $this->assertSame('fake-model', $summary['usage']['model']);
    }

    public function test_a_queued_summary_for_another_tenants_conversation_is_skipped(): void
    {
        $this->fakeModel();

        $other = $this->tenantWithStore();
        $otherConversation = $this->withinTenant($other, fn (): Conversation => Conversation::factory()->create([
            'tenant_id' => $other->getKey(),
        ]));

        $job = new RunAiUseCase(
            'summary',
            ['conversation_id' => (int) $otherConversation->getKey(), 'user_id' => (int) $this->merchant->getKey()],
            (int) $this->tenant->getKey(),
        );

        $job->handle(
            $this->app->make(FeatureRegistry::class),
            $this->app->make(TenantContext::class),
            $this->app->make(IdempotencyStore::class),
        );

        $fresh = Conversation::query()->withoutGlobalScopes()->findOrFail($otherConversation->getKey());

        $this->assertArrayNotHasKey('ai_summary', $fresh->meta ?? []);
    }

    public function test_a_repeated_job_with_the_same_idempotency_key_does_not_run_again(): void
    {
        $conversation = $this->conversationWithMessage();

        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'summary' => 'First summary.',
                'intent' => 'other',
                'sentiment' => 'neutral',
                'needs_human_reply' => false,
                'suggested_next_step' => 'No action needed.',
                'referenced_order_numbers' => [],
            ],
            model: 'fake-model',
        ));

        $payload = ['conversation_id' => (int) $conversation->getKey(), 'user_id' => (int) $this->merchant->getKey()];
        $key = 'summary-'.$conversation->getKey();

        $job = new RunAiUseCase('summary', $payload, (int) $this->tenant->getKey(), $key);
        $job->handle(
            $this->app->make(FeatureRegistry::class),
            $this->app->make(TenantContext::class),
            $this->app->make(IdempotencyStore::class),
        );

        // A second run with a different answer must not overwrite the first.
        $this->fakeModel(new AiCompletion(
            text: 'ignored',
            structured: [
                'summary' => 'Second summary.',
                'intent' => 'other',
                'sentiment' => 'neutral',
                'needs_human_reply' => false,
                'suggested_next_step' => 'No action needed.',
                'referenced_order_numbers' => [],
            ],
            model: 'fake-model',
        ));

        (new RunAiUseCase('summary', $payload, (int) $this->tenant->getKey(), $key))->handle(
            $this->app->make(FeatureRegistry::class),
            $this->app->make(TenantContext::class),
            $this->app->make(IdempotencyStore::class),
        );

        $fresh = Conversation::query()->withoutGlobalScopes()->findOrFail($conversation->getKey());

        $this->assertSame('First summary.', $fresh->meta['ai_summary']['summary']);
    }
}
