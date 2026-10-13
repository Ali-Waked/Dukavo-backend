<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\RoleScope;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Rbac\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stock ledger and its endpoint.
 *
 * The ledger is append-only: a correction is an opposing movement, never an edit
 * to history. These tests assert that the balance on the row and the last
 * `balance_after` in the ledger always agree, because a disagreement is how a
 * merchant ends up overselling.
 */
class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->store = Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();

        $this->inventory = app(InventoryService::class);
    }

    public function test_receiving_stock_records_a_movement_and_raises_the_balance(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 0);
        $row = $this->inventoryRow();

        $movement = $this->withinTenant($this->tenant, fn (): InventoryMovement => $this->inventory->applyMovement(
            $row,
            InventoryMovementType::Purchase,
            40,
            'Supplier delivery',
        ));

        $this->assertSame(40, $movement->quantity);
        $this->assertSame(40, $movement->balance_after);
        $this->assertSame(40, $row->fresh()->quantity);
        $this->assertSame($variant->getKey(), $row->product_variant_id);
    }

    public function test_a_stock_take_states_the_new_truth_rather_than_a_delta(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 30);
        $row = $this->inventoryRow();

        $movement = $this->withinTenant($this->tenant, fn (): InventoryMovement => $this->inventory->adjustToQuantity(
            $row,
            27,
            'Counted on the shelf',
        ));

        // The row said 30; the count says 27. The movement records the change.
        $this->assertSame(-3, $movement->quantity);
        $this->assertSame(27, $movement->balance_after);
        $this->assertSame(27, $row->fresh()->quantity);
    }

    public function test_a_stock_take_cannot_go_below_reserved_stock(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $row = $this->inventoryRow();

        $this->withinTenant($this->tenant, fn () => $this->inventory->reserve($row, 6));

        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn () => $this->inventory->adjustToQuantity($row, 2));
    }

    public function test_stock_cannot_be_spent_below_zero(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 3);
        $row = $this->inventoryRow();

        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn () => $this->inventory->applyMovement(
            $row,
            InventoryMovementType::Sale,
            5,
        ));
    }

    public function test_reserving_and_releasing_leaves_the_balance_untouched(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $row = $this->inventoryRow();

        $this->withinTenant($this->tenant, function () use ($row): void {
            $this->inventory->reserve($row, 4);
        });

        $this->assertSame(10, $row->fresh()->quantity);
        $this->assertSame(4, $row->fresh()->reserved_quantity);

        $this->withinTenant($this->tenant, fn () => $this->inventory->release(
            $this->store,
            [['product_variant_id' => $row->product_variant_id, 'store_id' => $this->store->getKey(), 'quantity' => 4]],
        ));

        $this->assertSame(10, $row->fresh()->quantity);
        $this->assertSame(0, $row->fresh()->reserved_quantity);
    }

    public function test_reservations_never_go_below_zero(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $row = $this->inventoryRow();

        $this->withinTenant($this->tenant, fn () => $this->inventory->release(
            $this->store,
            [['product_variant_id' => $row->product_variant_id, 'store_id' => $this->store->getKey(), 'quantity' => 99]],
        ));

        $this->assertSame(0, $row->fresh()->reserved_quantity);
    }

    public function test_a_reservation_beyond_available_stock_is_refused(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 2);
        $row = $this->inventoryRow();

        $this->expectException(BusinessRuleViolation::class);

        $this->withinTenant($this->tenant, fn () => $this->inventory->reserve($row, 5));
    }

    public function test_low_stock_rows_are_the_ones_at_or_below_their_threshold(): void
    {
        ['variant' => $low] = $this->stockedProduct($this->tenant, $this->store, quantity: 3);
        $this->stockedProduct($this->tenant, $this->store, quantity: 50);

        $rows = $this->withinTenant($this->tenant, fn () => $this->inventory->lowStock());

        $this->assertCount(1, $rows);
        $this->assertSame($low->getKey(), $rows->first()->product_variant_id);
    }

    public function test_a_row_without_a_threshold_is_never_low(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 0);

        $this->withinTenant($this->tenant, function (): void {
            Inventory::query()->update(['low_stock_threshold' => null]);
        });

        $this->assertCount(0, $this->withinTenant($this->tenant, fn () => $this->inventory->lowStock()));
    }

    public function test_the_adjust_endpoint_writes_the_ledger_and_attributes_the_user(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store, quantity: 10);
        $user = $this->memberOf($this->tenant);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/adjust', [
                'store_id' => $this->store->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 12,
                'note' => 'Recount',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.balance_after', 12);

        $movement = $this->withinTenant(
            $this->tenant,
            fn (): InventoryMovement => InventoryMovement::query()->latest('id')->firstOrFail(),
        );

        $this->assertSame($user->getKey(), $movement->user_id);
        $this->assertSame('Recount', $movement->note);
        $this->assertSame(12, $this->inventoryRow()->quantity);
    }

    public function test_the_receive_endpoint_is_refused_without_permission(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        // A purpose-made role that may look at stock but never change it. The
        // seeded roles are all-or-nothing, so the fine-grained case needs a real
        // custom role rather than a hand-edited pivot.
        $auditor = app(RoleService::class)->createRole(
            'Stock Auditor',
            RoleScope::Tenant,
            'Read-only access to stock.',
            ['inventory.view'],
        );

        $viewer = $this->memberWithRole($this->tenant, $auditor);

        $this->actingAs($viewer)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/receive', [
                'store_id' => $this->store->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => 5,
            ])
            ->assertForbidden();

        $this->assertSame(20, $this->inventoryRow()->quantity);
    }

    public function test_a_variant_of_another_tenant_cannot_be_stocked(): void
    {
        $other = $this->tenantWithStore();
        $otherStore = Store::query()->withoutGlobalScopes()->where('tenant_id', $other->getKey())->firstOrFail();
        ['variant' => $foreignVariant] = $this->stockedProduct($other, $otherStore);

        $user = $this->memberOf($this->tenant);

        $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson('/api/v1/tenant/inventory/adjust', [
                'store_id' => $this->store->getKey(),
                'product_variant_id' => $foreignVariant->getKey(),
                'quantity' => 5,
            ])
            // Not a validation error: the variant does not exist for this tenant,
            // so it is indistinguishable from a made-up id.
            ->assertNotFound();
    }

    public function test_the_movement_listing_is_tenant_scoped(): void
    {
        $this->stockedProduct($this->tenant, $this->store, quantity: 5);
        $row = $this->inventoryRow();
        $user = $this->memberOf($this->tenant);

        $this->withinTenant($this->tenant, fn () => $this->inventory->applyMovement($row, InventoryMovementType::Purchase, 5));

        $this->actingAs($user)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/tenant/inventory/'.$row->getKey().'/movements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'purchase')
            ->assertJsonPath('data.0.direction', 'in');
    }

    private function inventoryRow(): Inventory
    {
        return Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->firstOrFail();
    }

    /**
     * A user holding one specific role inside the tenant.
     */
    private function memberWithRole(Tenant $tenant, Role $role): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->getKey(), ['is_owner' => false]);
        $user->roleAssignments()->create([
            'role_id' => $role->getKey(),
            'tenant_id' => $tenant->getKey(),
        ]);

        return $user->fresh();
    }
}
