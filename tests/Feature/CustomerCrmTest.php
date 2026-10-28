<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleScope;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMemory;
use App\Models\CustomerSummary;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Contracts\LanguageModel;
use App\Services\Ai\Data\AiCompletion;
use App\Services\Ai\Exceptions\AiRequestFailed;
use App\Services\Ai\Providers\AiProviderFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProviderFactory;
use Tests\Support\FakeLanguageModel;
use Tests\TestCase;

/**
 * Customer Memory and Smart CRM.
 *
 * The tests here are the contract for the privacy-sensitive parts: a customer
 * belongs to exactly one tenant, a memory is an explicit, editable business
 * fact, and an AI summary is a staff-editable draft rather than an authority.
 */
class CustomerCrmTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
        $this->merchant = $this->memberOf($this->tenant, 'merchant');

        config()->set('ai.enabled', true);
        config()->set('ai.features.customer_summary.enabled', true);
        config()->set('ai.features.customer_summary.daily_per_user', 100);
        config()->set('ai.features.customer_summary.daily_per_tenant', 100);

        $this->fakeModel(new AiCompletion(
            text: '{}',
            structured: [
                'summary' => 'Customer has a short order history and prefers quick replies.',
                'highlights' => ['Repeat customer'],
                'risks' => [],
                'opportunities' => ['Offer a follow-up'],
            ],
            model: 'fake-model',
            inputTokens: 120,
            outputTokens: 40,
            totalTokens: 160,
        ));
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

    public function test_overview_returns_profile_stats_and_timeline_scoped_to_one_tenant(): void
    {
        $customer = $this->customer('Layla Hassan');

        $this->order($customer, 120.00, PaymentStatus::Paid, OrderStatus::Delivered);
        $this->order($customer, 80.00, PaymentStatus::Unpaid, OrderStatus::Pending);

        $conversation = $this->withinTenant($this->tenant, fn (): Conversation => Conversation::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'customer_id' => $customer->getKey(),
            'subject' => 'Delivery question',
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.crm.overview', $customer))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->getKey())
            ->assertJsonPath('data.stats.orders_count', 2)
            ->assertJsonPath('data.stats.total_spent', 120)
            ->assertJsonPath('data.stats.paid_orders_count', 1);

        $overview = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.crm.overview', $customer))
            ->assertOk()
            ->json('data');

        $this->assertNotNull($overview['stats']['last_interaction_at'] ?? null);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.crm.timeline', $customer))
            ->assertOk()
            ->assertJsonFragment(['type' => 'conversation'])
            ->assertJsonFragment(['type' => 'order']);

        $this->assertSame($conversation->customer_id, $customer->getKey());
    }

    public function test_a_customer_from_another_tenant_is_never_visible_or_editable(): void
    {
        $otherTenant = $this->tenantWithStore();
        $foreign = $this->withinTenant($otherTenant, fn (): Customer => Customer::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'name' => 'Other Tenant Customer',
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.crm.overview', $foreign))
            ->assertNotFound();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $foreign), [
                'key' => 'language',
                'value' => 'Arabic',
            ])
            ->assertNotFound();
    }

    public function test_cross_tenant_customer_matching_uses_conservative_email_or_phone_and_stays_inside_tenant(): void
    {
        $customer = $this->customer('Noor Salem', 'noor@example.com', '+15551230000');

        $otherTenant = $this->tenantWithStore();
        $this->withinTenant($otherTenant, fn () => Customer::factory()->create([
            'tenant_id' => $otherTenant->getKey(),
            'name' => 'Noor Salem',
            'email' => 'noor@example.com',
            'phone' => '+15551230000',
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.index', ['search' => 'noor@example.com']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->getKey());
    }

    public function test_a_deleted_memory_key_can_be_reused_without_duplicating_the_row(): void
    {
        $customer = $this->customer('Reusable Key Customer');

        $created = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $customer), [
                'key' => 'preferred_language',
                'value' => 'Arabic',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson(route('api.tenant.customers.memories.destroy', [$customer, $created['id']]))
            ->assertNoContent();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $customer), [
                'key' => 'preferred_language',
                'value' => 'English',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.value', 'English');

        $this->assertNotSoftDeleted('customer_memories', ['id' => $created['id']]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $customer), [
                'key' => 'preferred_language',
                'value' => 'French',
            ])
            ->assertStatus(422);
    }

    public function test_memory_can_be_created_edited_and_deleted_by_an_authorised_member(): void
    {
        $customer = $this->customer('Mona Ali');

        $created = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $customer), [
                'key' => 'preferred_language',
                'value' => 'Arabic',
                'category' => 'communication',
                'source' => 'customer_stated',
                'confidence' => 90,
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'preferred_language')
            ->assertJsonPath('data.confidence', 90)
            ->json('data');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.memories.index', $customer))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.customers.memories.update', [$customer, $created['id']]), [
                'value' => 'English',
            ])
            ->assertOk()
            ->assertJsonPath('data.value', 'English');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson(route('api.tenant.customers.memories.destroy', [$customer, $created['id']]))
            ->assertNoContent();

        $this->assertSoftDeleted('customer_memories', ['id' => $created['id']]);
    }

    public function test_a_member_without_customers_update_cannot_create_or_edit_memory(): void
    {
        $customer = $this->customer('Rami Farah');
        $viewer = $this->memberOfWithPermissions($this->tenant, ['customers.view']);

        $memory = $this->withinTenant($this->tenant, fn (): CustomerMemory => CustomerMemory::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'customer_id' => $customer->getKey(),
            'key' => 'language',
            'value' => 'English',
            'category' => 'communication',
            'source' => 'manual',
        ]));

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.memories.store', $customer), [
                'key' => 'language',
                'value' => 'Arabic',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.customers.memories.update', [$customer, $memory]), [
                'value' => 'Arabic',
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson(route('api.tenant.customers.memories.destroy', [$customer, $memory]))
            ->assertForbidden();
    }

    public function test_memory_from_another_customer_cannot_be_edited_through_a_different_customer_url(): void
    {
        $customerA = $this->customer('Customer A');
        $customerB = $this->customer('Customer B');

        $memory = $this->withinTenant($this->tenant, fn (): CustomerMemory => CustomerMemory::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'customer_id' => $customerA->getKey(),
            'key' => 'language',
            'value' => 'Arabic',
            'category' => 'communication',
            'source' => 'manual',
        ]));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.customers.memories.update', [$customerB, $memory]), [
                'value' => 'French',
            ])
            ->assertNotFound();
    }

    public function test_memory_is_isolated_between_tenants_and_cannot_be_read_through_a_foreign_customer(): void
    {
        $customer = $this->customer('Isolated Customer');

        $this->withinTenant($this->tenant, fn (): CustomerMemory => CustomerMemory::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'customer_id' => $customer->getKey(),
            'key' => 'allergy',
            'value' => 'Peanuts',
            'category' => 'product',
            'source' => 'customer_stated',
        ]));

        $otherTenant = $this->tenantWithStore();
        $otherMerchant = $this->memberOf($otherTenant, 'merchant');

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.customers.memories.index', $customer))
            ->assertNotFound();
    }

    public function test_summary_is_created_on_demand_and_staff_can_correct_it(): void
    {
        $customer = $this->customer('Summary Customer');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.customers.crm.summary', $customer))
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->getKey());

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.customers.crm.summary.update', $customer), [
                'summary' => 'Prefers evening delivery and has asked for Arabic updates.',
                'highlights' => ['Evening delivery', 'Arabic updates'],
            ])
            ->assertOk()
            ->assertJsonPath('data.summary', 'Prefers evening delivery and has asked for Arabic updates.')
            ->assertJsonPath('data.is_edited', true)
            ->assertJsonPath('data.edited_by.id', $this->merchant->getKey());
    }

    public function test_summary_refresh_requires_ai_permission_and_marks_itself_staff_editable(): void
    {
        $customer = $this->customer('Refresh Customer');
        $viewer = $this->memberOfWithPermissions($this->tenant, ['customers.view']);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.crm.summary.refresh', $customer))
            ->assertForbidden();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.crm.summary.refresh', $customer))
            ->assertOk()
            ->assertJsonPath('data.customer_id', $customer->getKey())
            ->assertJsonPath('data.summary', 'Customer has a short order history and prefers quick replies.')
            ->assertJsonPath('data.model', 'fake-model')
            ->assertJsonPath('data.is_edited', false);

        $this->assertDatabaseHas('customer_summaries', [
            'customer_id' => $customer->getKey(),
            'tenant_id' => $this->tenant->getKey(),
            'is_edited' => false,
        ]);
    }

    public function test_summary_failure_leaves_the_existing_corrected_summary_intact(): void
    {
        $customer = $this->customer('Failing Summary Customer');

        $summary = $this->withinTenant($this->tenant, fn (): CustomerSummary => CustomerSummary::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'customer_id' => $customer->getKey(),
            'summary' => 'Staff corrected summary.',
            'is_edited' => true,
            'edited_by' => $this->merchant->getKey(),
            'edited_at' => now(),
        ]));

        $this->fakeModel(null, AiRequestFailed::invalidOutput('simulated provider failure'));

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.customers.crm.summary.refresh', $customer))
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $fresh = CustomerSummary::query()->withoutGlobalScopes()->findOrFail($summary->getKey());
        $this->assertSame('Staff corrected summary.', $fresh->summary);
        $this->assertTrue($fresh->is_edited);
        $this->assertSame($this->merchant->getKey(), $fresh->edited_by);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $customer = $this->customer('Private Customer');

        $this->getJson(route('api.tenant.customers.crm.overview', $customer))
            ->assertUnauthorized();
    }

    private function customer(string $name, ?string $email = null, ?string $phone = null): Customer
    {
        return $this->withinTenant($this->tenant, fn (): Customer => Customer::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]));
    }

    private function order(Customer $customer, float $total, PaymentStatus $payment, OrderStatus $status): Order
    {
        return $this->withinTenant($this->tenant, fn (): Order => Order::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'customer_id' => $customer->getKey(),
            'grand_total' => $total,
            'payment_status' => $payment->value,
            'status' => $status->value,
            'currency' => 'USD',
        ]));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function memberOfWithPermissions(Tenant $tenant, array $permissions): User
    {
        $role = Role::query()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Limited '.fake()->unique()->word(),
            'slug' => 'limited-'.fake()->unique()->slug(2),
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = Permission::query()->whereIn('slug', $permissions)->pluck('id');
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return $user->fresh();
    }
}
