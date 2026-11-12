<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\AiUsage;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The merchant's own AI settings endpoints.
 *
 * Everything here is scoped to the workspace resolved from the `X-Tenant`
 * header: a merchant reads and changes only their workspace's feature
 * switches, BYOK opt-in and credentials, and their consumption numbers
 * never include another workspace's. BYOK is refused while the platform has
 * it disabled — the platform flag is the ceiling, not a default.
 */
class TenantAiSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config()->set('ai.enabled', true);
        config()->set('ai.byok.enabled', false);
    }

    /**
     * A merchant owner with the `X-Tenant` header set for their workspace.
     */
    private function merchant(Tenant $tenant): User
    {
        return $this->memberOf($tenant, 'merchant');
    }

    /**
     * An enabled provider row, as the platform would have registered it.
     */
    private function provider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'OpenAI',
            'driver' => 'openai_compatible',
            'enabled' => true,
            'priority' => 100,
        ]);
    }

    public function test_staff_without_the_permission_cannot_read_the_ai_settings(): void
    {
        $tenant = $this->tenantWithStore();
        $staff = $this->memberOf($tenant, 'staff');

        $this->actingAs($staff, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/ai-settings')
            ->assertForbidden();
    }

    public function test_the_settings_overview_lists_features_with_the_platform_ceiling(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        config()->set('ai.features.content.enabled', false);

        $response = $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/ai-settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.byok.platform_enabled', false)
            ->assertJsonPath('data.byok.opted_in', false);

        $features = collect($response->json('data.features'));

        $this->assertNotEmpty($features);
        $this->assertTrue($features->firstWhere('feature', 'content')['platform_enabled'] === false);
        $this->assertTrue($features->firstWhere('feature', 'content')['enabled'] === false);
        $this->assertTrue($features->firstWhere('feature', 'assistant')['platform_enabled'] === true);
    }

    public function test_a_workspace_can_only_narrow_the_platform_policy(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        config()->set('ai.features.content.enabled', false);

        // Enabling a feature the platform has off is refused loudly.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', [
                'features' => ['content' => true],
            ])
            ->assertStatus(422);

        // Disabling one the platform allows works, and is a patch: the
        // features left out keep their current state.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', [
                'features' => ['content' => false, 'assistant' => false],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $tenant = $tenant->refresh();
        $disabled = $tenant->settings['ai_disabled_features'];

        $this->assertContains('assistant', $disabled);
        $this->assertContains('content', $disabled);

        // Re-enabling assistant, with content still disabled, leaves content
        // alone: the payload is a patch, not a whole-list replacement.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', [
                'features' => ['assistant' => true],
            ])
            ->assertOk();

        $disabled = $tenant->refresh()->settings['ai_disabled_features'];

        $this->assertNotContains('assistant', $disabled);
        $this->assertContains('content', $disabled);
    }

    public function test_an_unknown_feature_is_refused(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', [
                'features' => ['time_travel' => true],
            ])
            ->assertStatus(422);
    }

    public function test_byok_opt_in_is_refused_while_the_platform_has_it_disabled(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', [
                'byok_opt_in' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertFalse((bool) ($tenant->refresh()->settings['ai_byok_opt_in'] ?? false));
    }

    public function test_a_workspace_cannot_store_credentials_while_byok_is_disabled(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);
        $this->provider();

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/ai-settings/credentials', [
                'provider' => 'openai',
                'api_key' => 'sk-tenant-early-bird',
            ])
            ->assertForbidden();

        $this->assertSame(0, AiProviderCredential::query()->whereNotNull('tenant_id')->count());
    }

    public function test_a_workspace_manages_its_own_credential_when_byok_is_enabled(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        config()->set('ai.byok.enabled', true);
        $this->provider();

        // The opt-in is the workspace's own decision once the platform allows it.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->patchJson('/api/v1/tenant/ai-settings', ['byok_opt_in' => true])
            ->assertOk()
            ->assertJsonPath('data.byok.opted_in', true);

        $response = $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/ai-settings/credentials', [
                'provider' => 'openai',
                'api_key' => 'sk-tenant-workspace-key-9042',
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner', 'tenant')
            ->assertJsonPath('data.tenant_id', $tenant->getKey())
            ->assertJsonPath('data.masked_key', '••••••••••••9042');

        // The plaintext never appears anywhere in the response.
        $this->assertStringNotContainsString('sk-tenant-workspace-key-9042', $response->getContent());

        $credential = AiProviderCredential::query()
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();

        // The stored key decrypts to the exact tenant key.
        $this->assertSame('sk-tenant-workspace-key-9042', $credential->decryptKey());

        // The listing shows the workspace's credential, masked.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->getJson('/api/v1/tenant/ai-settings/credentials')
            ->assertOk()
            ->assertJsonPath('data.byok.platform_enabled', true)
            ->assertJsonPath('data.byok.opted_in', true);

        // Revocation is the workspace's own action.
        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->deleteJson("/api/v1/tenant/ai-settings/credentials/{$credential->getKey()}")
            ->assertOk();

        $this->assertNotNull($credential->refresh()->revoked_at);
    }

    public function test_workspace_credentials_are_isolated_between_workspaces(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $myMerchant = $this->merchant($mine);
        $theirMerchant = $this->merchant($theirs);

        config()->set('ai.byok.enabled', true);

        $provider = $this->provider();

        $theirCredential = AiProviderCredential::query()->create([
            'provider_id' => $provider->getKey(),
            'tenant_id' => $theirs->getKey(),
            'api_key' => encrypt('sk-their-own-private-key-99'),
            'key_hint' => 'e-99',
            'revoked_at' => null,
        ]);

        // Their credential never appears in my listing.
        $response = $this->actingAs($myMerchant, 'sanctum')
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/ai-settings/credentials')
            ->assertOk();

        $this->assertEmpty($response->json('data.credentials'));

        // Addressing their row from my workspace is a 404, not a forbidden
        // that would confirm the id exists.
        $this->actingAs($myMerchant, 'sanctum')
            ->withHeader('X-Tenant', $mine->slug)
            ->deleteJson("/api/v1/tenant/ai-settings/credentials/{$theirCredential->getKey()}")
            ->assertNotFound();

        $this->assertNull($theirCredential->refresh()->revoked_at);
    }

    public function test_the_connection_test_exercises_the_workspaces_own_key(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);

        config()->set('ai.byok.enabled', true);

        // A platform credential exists for the same provider: the workspace
        // test must use the tenant's key or nothing, never this one.
        $provider = $this->provider();

        AiProviderCredential::query()->create([
            'provider_id' => $provider->getKey(),
            'tenant_id' => null,
            'api_key' => encrypt('sk-platform-decoy-key-7777'),
            'key_hint' => '7777',
            'revoked_at' => null,
        ]);

        AiProviderCredential::query()->create([
            'provider_id' => $provider->getKey(),
            'tenant_id' => $tenant->getKey(),
            'api_key' => encrypt('sk-tenant-real-key-1234'),
            'key_hint' => '1234',
            'revoked_at' => null,
        ]);

        Http::fake(function ($request) {
            return Http::response([
                'id' => 'chatcmpl-test',
                'choices' => [['message' => ['content' => 'pong']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200);
        });

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/ai-settings/credentials/test', [
                'provider' => 'openai',
            ])
            ->assertOk()
            ->assertJsonPath('data.result.ok', true);

        // The bearer token on the wire is the workspace's key — the proof
        // that the tenant check can never silently pass on the platform's
        // credential.
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk-tenant-real-key-1234');
        });

        Http::assertNotSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk-platform-decoy-key-7777');
        });
    }

    public function test_the_connection_test_needs_the_byok_flag(): void
    {
        $tenant = $this->tenantWithStore();
        $merchant = $this->merchant($tenant);
        $this->provider();

        $this->actingAs($merchant, 'sanctum')
            ->withHeader('X-Tenant', $tenant->slug)
            ->postJson('/api/v1/tenant/ai-settings/credentials/test', [
                'provider' => 'openai',
            ])
            ->assertForbidden();
    }

    public function test_workspace_usage_is_scoped_to_the_workspace(): void
    {
        $mine = $this->tenantWithStore();
        $theirs = $this->tenantWithStore();
        $myMerchant = $this->merchant($mine);

        AiUsage::query()->create([
            'tenant_id' => $mine->getKey(),
            'feature' => 'translation',
            'task' => 'translation',
            'correlation_id' => str_repeat('a', 40),
            'provider' => 'openai',
            'total_tokens' => 100,
            'estimated_cost' => 0.01,
            'status' => 'success',
            'created_at' => now(),
        ]);

        AiUsage::query()->create([
            'tenant_id' => $mine->getKey(),
            'feature' => 'translation',
            'task' => 'translation',
            'correlation_id' => str_repeat('a', 40),
            'provider' => 'anthropic',
            'total_tokens' => 200,
            'estimated_cost' => 0.02,
            'fallback_attempts' => 1,
            'status' => 'success',
            'created_at' => now(),
        ]);

        AiUsage::query()->create([
            'tenant_id' => $theirs->getKey(),
            'feature' => 'translation',
            'task' => 'translation',
            'provider' => 'gemini',
            'total_tokens' => 999999,
            'estimated_cost' => 500.0,
            'status' => 'success',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($myMerchant, 'sanctum')
            ->withHeader('X-Tenant', $mine->slug)
            ->getJson('/api/v1/tenant/ai-settings/usage')
            ->assertOk()
            ->assertJsonPath('data.today_requests', 1)
            ->assertJsonPath('data.requests', 1)
            ->assertJsonPath('data.tokens', 300)
            ->assertJsonPath('data.estimated_cost_usd', 0.03);

        // Their workspace's numbers never appear in mine.
        $payload = json_encode($response->json('data'));

        $this->assertStringNotContainsString('999999', (string) $payload);
        $this->assertStringNotContainsString('500', (string) $payload);

        $byFeature = collect($response->json('data.by_feature'));

        $this->assertSame(1, $byFeature->count());
        $this->assertSame('translation', $byFeature->first()['feature']);
    }
}
