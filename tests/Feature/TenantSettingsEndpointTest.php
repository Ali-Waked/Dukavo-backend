<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The workspace settings endpoint.
 *
 * Two boundaries matter. First, who may look at and who may change the tenant:
 * `settings.view` / `settings.manage` are granted to merchants and super admins
 * only, and staff must be refused exactly these endpoints, nothing more. Second,
 * what the request may touch: the slug is never accepted, so renaming the
 * business can never break the stable identifier the tenant header depends on.
 */
class TenantSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $merchant;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');
        $this->staff = $this->memberOf($this->tenant, 'staff');
    }

    public function test_a_merchant_can_read_their_workspace_settings(): void
    {
        $this->actingInTenant($this->merchant)
            ->getJson('/api/v1/tenant/settings')
            ->assertOk()
            ->assertJsonPath('data.slug', $this->tenant->slug)
            ->assertJsonPath('data.currency', $this->tenant->currency)
            ->assertJsonPath('data.timezone', $this->tenant->timezone);
    }

    public function test_staff_cannot_read_workspace_settings(): void
    {
        $this->actingInTenant($this->staff)
            ->getJson('/api/v1/tenant/settings')
            ->assertForbidden();
    }

    public function test_a_merchant_can_update_the_editable_fields(): void
    {
        $this->actingInTenant($this->merchant)
            ->patchJson('/api/v1/tenant/settings', [
                'name' => 'The Urban Basket Co.',
                'contact_email' => 'hello@urban-basket.test',
                'phone' => '+9715511223344',
                'currency' => 'AED',
                'timezone' => 'Asia/Dubai',
                'locale' => 'ar',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'The Urban Basket Co.')
            ->assertJsonPath('data.contact_email', 'hello@urban-basket.test')
            ->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.timezone', 'Asia/Dubai')
            ->assertJsonPath('data.locale', 'ar');

        $tenant = $this->tenant->fresh();
        $this->assertSame('The Urban Basket Co.', $tenant->name);
        $this->assertSame('AED', $tenant->currency);
        $this->assertSame('ar', $tenant->locale);
    }

    public function test_the_slug_is_immutable_even_when_requested(): void
    {
        $this->actingInTenant($this->merchant)
            ->patchJson('/api/v1/tenant/settings', [
                'name' => 'Renamed Business',
                'slug' => 'taken-over-slug',
            ])
            ->assertOk();

        $tenant = $this->tenant->fresh();
        $this->assertSame('Renamed Business', $tenant->name);
        $this->assertSame($this->tenant->slug, $tenant->slug);
    }

    public function test_staff_cannot_update_workspace_settings(): void
    {
        $this->actingInTenant($this->staff)
            ->patchJson('/api/v1/tenant/settings', ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame($this->tenant->name, $this->tenant->fresh()->name);
    }

    public function test_the_currency_and_locale_must_be_valid(): void
    {
        $this->actingInTenant($this->merchant)
            ->patchJson('/api/v1/tenant/settings', ['currency' => 'US'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');

        $this->actingInTenant($this->merchant)
            ->patchJson('/api/v1/tenant/settings', ['locale' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    public function test_updating_settings_lands_in_the_tenant_audit_trail(): void
    {
        $this->actingInTenant($this->merchant)
            ->patchJson('/api/v1/tenant/settings', ['currency' => 'AED'])
            ->assertOk();

        $entry = AuditLog::query()
            ->where('auditable_type', (new Tenant)->getMorphClass())
            ->where('auditable_id', $this->tenant->getKey())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('updated', $entry->action->value);
        $this->assertSame('USD', $entry->old_values['currency']);
        $this->assertSame('AED', $entry->new_values['currency']);
    }

    private function actingInTenant(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-Tenant', $this->tenant->slug);
    }
}
