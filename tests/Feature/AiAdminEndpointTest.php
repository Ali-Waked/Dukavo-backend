<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AiTask as AiTaskEnum;
use App\Models\AiActivityLog;
use App\Models\AiPromptTemplate;
use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\AiTask;
use App\Models\AiTaskRoute;
use App\Models\AiUsage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform AI administration endpoints.
 *
 * The whole dashboard hangs together on a few invariants this file pins
 * down: platform credentials are encrypted at rest and only ever leave the
 * server masked, providers can only be registered from the driver
 * catalogue, routing chains replace themselves whole, prompt versions
 * append rather than edit, and the usage ledger counts logical requests
 * (rows sharing a correlation id) separately from billed attempts.
 */
class AiAdminEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config()->set('ai.enabled', true);
    }

    private function admin(): User
    {
        return $this->platformUser('dukavo-admin');
    }

    /**
     * Register a catalogue provider and return its row.
     */
    private function registeredProvider(string $key = 'openai'): AiProvider
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/providers', ['key' => $key])
            ->assertCreated();

        return AiProvider::query()->where('key', $key)->firstOrFail();
    }

    /**
     * Seed the routed task rows and return the task addressed by key.
     */
    private function seededTask(string $key): AiTask
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/tasks/seed')
            ->assertOk();

        return AiTask::query()->where('key', $key)->firstOrFail();
    }

    public function test_a_merchant_cannot_reach_the_ai_administration(): void
    {
        $merchant = $this->memberOf($this->tenantWithStore(), 'merchant');

        $this->actingAs($merchant, 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/overview')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_the_overview_reports_the_installations_posture(): void
    {
        $provider = $this->registeredProvider();
        $this->seededTask('translation');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials", [
                'api_key' => 'sk-platform-overview-key',
            ])
            ->assertCreated();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/overview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.byok.enabled', false)
            ->assertJsonPath('data.counts.providers', 1)
            ->assertJsonPath('data.counts.providers_enabled', 1)
            ->assertJsonPath('data.counts.credentials', 1);

        $this->assertSame(count(AiTaskEnum::cases()), $response->json('data.counts.tasks'));
        $this->assertSame('platform', $response->json('data.providers.0.configuration.source'));

        // The registered key leaves the catalogue of unregistered drivers.
        $unregistered = collect($response->json('data.unregistered_catalog'))
            ->pluck('key')
            ->all();

        $this->assertNotContains('openai', $unregistered);
        $this->assertContains('anthropic', $unregistered);
    }

    public function test_an_admin_without_the_rotate_permission_cannot_manage_credentials(): void
    {
        $provider = $this->registeredProvider();

        // Dukavo admins hold `ai_admin.rotate`; taking it away must close the
        // credential surface for them without touching anything else.
        $role = Role::query()->where('slug', 'dukavo-admin')->firstOrFail();
        $permission = Permission::query()->where('slug', 'ai_admin.rotate')->firstOrFail();

        $role->permissions()->detach($permission);
        app(PermissionService::class)->flushRoleCatalogue();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials", [
                'api_key' => 'sk-forbidden-rotation',
            ])
            ->assertForbidden();

        // The read surface is still open: rotation is the sensitive half.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/providers')
            ->assertOk();

        $this->assertDatabaseCount('ai_provider_credentials', 0);
    }

    public function test_registering_a_provider_is_idempotent_and_seeds_its_default_model(): void
    {
        $first = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/providers', ['key' => 'openai'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'openai');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/providers', ['key' => 'openai'])
            ->assertCreated();

        $this->assertSame(1, AiProvider::query()->where('key', 'openai')->count());
        $this->assertSame((int) $first->json('data.id'), AiProvider::query()->where('key', 'openai')->firstOrFail()->getKey());

        $provider = AiProvider::query()->where('key', 'openai')->firstOrFail();

        $this->assertSame(1, $provider->models()->count());
        $this->assertSame(1, $provider->models()->where('is_default', true)->count());
    }

    public function test_an_unknown_provider_key_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/providers', ['key' => 'not-a-provider'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');

        $this->assertDatabaseCount('ai_providers', 0);
    }

    public function test_a_credential_is_encrypted_at_rest_and_only_the_masked_hint_returns(): void
    {
        $provider = $this->registeredProvider();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials", [
                'api_key' => 'sk-platform-secret-1234',
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner', 'platform')
            ->assertJsonPath('data.masked_key', '••••••••••••1234');

        // The plaintext never appears anywhere in the response.
        $this->assertStringNotContainsString('sk-platform-secret-1234', $response->getContent());

        $atRest = (string) DB::table('ai_provider_credentials')
            ->where('provider_id', $provider->getKey())
            ->value('api_key');

        $this->assertStringNotContainsString('sk-platform-secret-1234', $atRest);
        $this->assertNotSame('sk-platform-secret-1234', $atRest);

        // It is a Laravel encrypted payload that round-trips to the exact key.
        $credential = AiProviderCredential::query()->where('provider_id', $provider->getKey())->firstOrFail();

        $this->assertSame('sk-platform-secret-1234', $credential->decryptKey());
    }

    public function test_a_local_base_url_is_refused(): void
    {
        $provider = $this->registeredProvider();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials", [
                'api_key' => 'sk-platform-loopback-key',
                'base_url' => 'http://127.0.0.1:8000/v1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_url');

        $this->assertDatabaseCount('ai_provider_credentials', 0);
    }

    public function test_revoking_a_credential_is_a_timestamp_not_a_delete(): void
    {
        $provider = $this->registeredProvider();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials", [
                'api_key' => 'sk-platform-revokable-key',
            ])
            ->assertCreated();

        $credential = AiProviderCredential::query()
            ->where('provider_id', $provider->getKey())
            ->whereNull('tenant_id')
            ->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/credentials/{$credential->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.revoked_at', fn ($revokedAt) => $revokedAt !== null);

        $this->assertNotNull($credential->refresh()->revoked_at);
        $this->assertSame(1, AiProviderCredential::query()->count());
    }

    public function test_testing_a_connection_without_a_credential_reports_not_configured(): void
    {
        $provider = $this->registeredProvider('openai');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/providers/{$provider->getKey()}/test-connection")
            ->assertOk()
            ->assertJsonPath('data.result.ok', false)
            ->assertJsonPath('data.result.reason', 'not_configured');
    }

    public function test_seeding_tasks_creates_every_routed_task_idempotently(): void
    {
        $first = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/tasks/seed')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(count(AiTaskEnum::cases()), $first->json('data.created'));
        $this->assertSame(
            collect(AiTaskEnum::cases())->pluck('value')->sort()->values()->all(),
            collect($first->json('data.tasks'))->sort()->values()->all(),
        );

        $second = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/tasks/seed')
            ->assertOk();

        $this->assertSame(0, $second->json('data.created'));
    }

    public function test_a_routing_chain_is_replaced_whole(): void
    {
        $primary = $this->registeredProvider('openai');
        $fallback = $this->registeredProvider('anthropic');
        $task = $this->seededTask('translation');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/platform/ai/admin/tasks/{$task->getKey()}/routes", [
                'routes' => [
                    ['provider_id' => $primary->getKey(), 'model' => 'gpt-4o-mini'],
                    ['provider_id' => $fallback->getKey(), 'model' => 'claude-3-5-haiku-latest'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $chain = AiTaskRoute::query()->where('task_id', $task->getKey())->orderBy('priority')->get();

        $this->assertSame(2, $chain->count());
        $this->assertSame($primary->getKey(), $chain[0]->provider_id);
        $this->assertFalse((bool) $chain[0]->is_fallback);
        $this->assertSame($fallback->getKey(), $chain[1]->provider_id);
        $this->assertTrue((bool) $chain[1]->is_fallback);

        // Replacing with a single hop must remove the old fallback: the chain
        // is applied whole, never merged.
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/platform/ai/admin/tasks/{$task->getKey()}/routes", [
                'routes' => [
                    ['provider_id' => $fallback->getKey()],
                ],
            ])
            ->assertOk();

        $chain = AiTaskRoute::query()->where('task_id', $task->getKey())->get();

        $this->assertSame(1, $chain->count());
        $this->assertSame($fallback->getKey(), $chain[0]->provider_id);
    }

    public function test_a_routing_hop_must_point_at_a_real_provider(): void
    {
        $task = $this->seededTask('translation');

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/platform/ai/admin/tasks/{$task->getKey()}/routes", [
                'routes' => [
                    ['provider_id' => 999999],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('routes.0.provider_id');

        $this->assertSame(0, AiTaskRoute::query()->where('task_id', $task->getKey())->count());
    }

    public function test_a_prompt_template_key_is_unique_per_task(): void
    {
        $task = $this->seededTask('translation');

        $payload = [
            'task_key' => 'translation',
            'key' => 'translation_v2',
            'name' => 'Translation instructions',
        ];

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/prompts', $payload)
            ->assertCreated();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/platform/ai/admin/prompts', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('key');

        $this->assertSame(1, AiPromptTemplate::query()->where('task_id', $task->getKey())->count());
    }

    public function test_prompt_versions_append_and_activation_moves_the_pointer(): void
    {
        $task = $this->seededTask('translation');

        $template = AiPromptTemplate::query()->create([
            'task_id' => $task->getKey(),
            'key' => 'translation_v3',
            'name' => 'Translation instructions',
        ]);

        $versionOne = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/prompts/{$template->getKey()}/versions", [
                'locale' => 'en',
                'system_prompt' => 'Translate faithfully.',
                'activate' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $versionOne['version']);
        $this->assertSame($versionOne['id'], $template->refresh()->active_version_id);

        $versionTwo = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/prompts/{$template->getKey()}/versions", [
                'locale' => 'en',
                'system_prompt' => 'Translate faithfully and keep brand tone.',
                'activate' => false,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(2, $versionTwo['version']);
        $this->assertSame($versionOne['id'], $template->refresh()->active_version_id);

        // Activation is a pointer move: version one stays untouched, version
        // two becomes the served instruction without any row being edited.
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/platform/ai/admin/prompts/{$template->getKey()}/versions/{$versionTwo['id']}/activate")
            ->assertOk();

        $this->assertSame($versionTwo['id'], $template->refresh()->active_version_id);
        $this->assertSame(2, $template->versions()->count());
    }

    public function test_usage_counts_logical_requests_and_bills_every_attempt(): void
    {
        $tenant = $this->tenantWithStore();

        // One logical request: a primary attempt plus the fallback that
        // rescued it, sharing a correlation id.
        AiUsage::query()->create([
            'tenant_id' => $tenant->getKey(),
            'feature' => 'translation',
            'task' => 'translation',
            'correlation_id' => str_repeat('a', 40),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost' => 0.001,
            'fallback_attempts' => 1,
            'status' => 'failed',
            'created_at' => now(),
        ]);

        AiUsage::query()->create([
            'tenant_id' => $tenant->getKey(),
            'feature' => 'translation',
            'task' => 'translation',
            'correlation_id' => str_repeat('a', 40),
            'provider' => 'anthropic',
            'model' => 'claude-3-5-haiku-latest',
            'input_tokens' => 120,
            'output_tokens' => 60,
            'total_tokens' => 180,
            'estimated_cost' => 0.002,
            'status' => 'success',
            'created_at' => now(),
        ]);

        // A legacy row with no correlation id counts as its own request.
        AiUsage::query()->create([
            'tenant_id' => null,
            'feature' => 'insights',
            'provider' => 'openai',
            'total_tokens' => 30,
            'estimated_cost' => 0.0005,
            'status' => 'success',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/usage')
            ->assertOk()
            ->assertJsonPath('success', true);

        $totals = $response->json('data.totals');

        $this->assertSame(2, $totals['requests']);
        $this->assertSame(1, $totals['failures']);
        $this->assertSame(360, $totals['tokens']);
        $this->assertSame(1, $totals['fallback_requests']);
        $this->assertSame(1, $totals['unique_tenants']);

        $byProvider = collect($response->json('data.by_provider'));

        $this->assertSame(2, $byProvider->firstWhere('provider', 'openai')['requests']);
        $this->assertSame(1, $byProvider->firstWhere('provider', 'anthropic')['requests']);

        $byTask = collect($response->json('data.by_task'));

        $this->assertSame(2, $byTask->firstWhere('task', 'translation')['requests']);
        $this->assertSame(1, $byTask->firstWhere('task', 'translation')['failures']);
    }

    public function test_activity_logs_are_filterable_by_correlation_id(): void
    {
        $tenant = $this->tenantWithStore();

        $correlation = str_repeat('c', 40);

        foreach (['openai', 'anthropic'] as $index => $provider) {
            AiActivityLog::query()->create([
                'correlation_id' => $correlation,
                'tenant_id' => $tenant->getKey(),
                'task' => 'translation',
                'feature' => 'translation',
                'provider' => $provider,
                'model' => 'gpt-4o-mini',
                'status' => 'success',
                'attempt' => $index + 1,
                'duration_ms' => 250,
                'input_tokens' => 10,
                'output_tokens' => 20,
                'created_at' => now(),
            ]);
        }

        AiActivityLog::query()->create([
            'correlation_id' => str_repeat('d', 40),
            'tenant_id' => $tenant->getKey(),
            'task' => 'translation',
            'provider' => 'gemini',
            'status' => 'failed',
            'error_category' => 'rate_limit',
            'attempt' => 1,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/activity-logs?correlation_id='.$correlation)
            ->assertOk();

        $rows = collect($response->json('data'));

        $this->assertSame(2, $rows->count());
        $this->assertTrue($rows->every(fn (array $row): bool => $row['correlation_id'] === $correlation));

        // Every attempt a logical request made is one row, ordered newest first.
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/platform/ai/admin/activity-logs')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
