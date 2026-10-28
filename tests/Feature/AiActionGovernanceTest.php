<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AiActionClassification;
use App\Enums\AiActionStatus;
use App\Enums\ConversationStatus;
use App\Enums\RoleScope;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AiAction;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Governance\AiActionService;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Rbac\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The AI trust and approval layer.
 *
 * These tests treat the model as hostile: a proposal has no effect, approval
 * requires current authority and explicit confirmation where the class demands
 * it, a stale decision cannot be used, and executing twice applies the change
 * once. The tenant boundary and the plan gate are exercised through the same
 * service the HTTP controller calls.
 */
class AiActionGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private User $merchantA;

    private User $merchantB;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private Inventory $inventoryA;

    private AiActionService $actions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenantA = $this->tenantWithStore();
        $this->tenantB = $this->tenantWithStore();

        $this->storeA = $this->storeFor($this->tenantA);
        $this->storeB = $this->storeFor($this->tenantB);

        ['variant' => $this->variantA] = $this->stockedProduct($this->tenantA, $this->storeA, quantity: 20);
        ['variant' => $this->variantB] = $this->stockedProduct($this->tenantB, $this->storeB, quantity: 20);

        $this->merchantA = $this->memberOf($this->tenantA, 'merchant');
        $this->merchantB = $this->memberOf($this->tenantB, 'merchant');

        $this->inventoryA = $this->withinTenant($this->tenantA, fn (): Inventory => Inventory::query()
            ->where('store_id', $this->storeA->getKey())
            ->where('product_variant_id', $this->variantA->getKey())
            ->firstOrFail());

        $this->actions = app(AiActionService::class);
    }

    public function test_a_proposal_is_a_draft_with_classification_and_expiry_and_changes_nothing(): void
    {
        $action = $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 12);

        $this->assertSame(AiActionStatus::Draft, $action->status);
        $this->assertSame(AiActionClassification::ReversibleChange, $action->classification);
        $this->assertNotNull($action->expires_at);
        $this->assertSame(20, $this->inventoryA->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_submitting_an_action_notifies_approvers_in_that_tenant_only(): void
    {
        $approver = $this->memberOf($this->tenantA, 'staff');

        $action = $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 16);
        $this->submit($action, $this->tenantA, $this->merchantA);

        $notification = $approver->notifications()
            ->where('event_key', Events::TENANT_AI_ACTION_AWAITING_REVIEW)
            ->firstOrFail();

        $this->assertSame('/ai/approvals', $notification->action_url);
        $this->assertSame($this->tenantA->getKey(), $notification->tenant_id);
        $this->assertSame(0, $this->merchantA->notifications()->count());
        $this->assertSame(0, $this->merchantB->notifications()->count());
    }

    public function test_reversible_actions_require_explicit_confirmation_to_approve(): void
    {
        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 9));

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, false));
            $this->fail('Approval without confirmation should have been refused.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertStringContainsString('explicit confirmation', $exception->getMessage());
        }

        $this->assertSame(AiActionStatus::PendingApproval, $action->fresh()->status);
        $this->assertSame(20, $this->inventoryA->fresh()->quantity);
    }

    public function test_approval_then_execution_applies_the_change_once_and_audits_it(): void
    {
        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 14));

        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));

        $action->refresh();

        $this->assertSame(AiActionStatus::Executed, $action->status);
        $this->assertSame($this->merchantA->getKey(), $action->executed_by);
        $this->assertSame(14, $this->inventoryA->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai_action_executed',
            'auditable_id' => $action->getKey(),
        ]);
    }

    public function test_replaying_an_execution_is_a_no_op(): void
    {
        $action = $this->approvedInventoryAction(11);

        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));

        $this->assertSame(11, $this->inventoryA->fresh()->quantity);
        $this->assertSame(1, InventoryMovement::query()->withoutGlobalScopes()->count());
        $this->assertSame(AiActionStatus::Executed, $action->fresh()->status);
    }

    public function test_the_same_idempotency_key_returns_the_original_proposal_per_tenant(): void
    {
        $first = $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 15, 'same-key');
        $again = $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 3, 'same-key');

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(1, AiAction::query()->withoutGlobalScopes()->where('idempotency_key', 'same-key')->count());

        $inventoryB = $this->withinTenant($this->tenantB, fn (): Inventory => Inventory::query()
            ->where('store_id', $this->storeB->getKey())
            ->where('product_variant_id', $this->variantB->getKey())
            ->firstOrFail());

        $other = $this->proposeInventory($this->tenantB, $this->merchantB, $inventoryB, 8, 'same-key');

        $this->assertNotSame($first->getKey(), $other->getKey());
        $this->assertSame(2, AiAction::query()->withoutGlobalScopes()->where('idempotency_key', 'same-key')->count());
    }

    public function test_a_stale_approval_expires_and_cannot_be_executed(): void
    {
        $action = $this->approvedInventoryAction(21);
        $action->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));
            $this->fail('An expired action should not execute.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $this->assertSame(AiActionStatus::Expired, $action->fresh()->status);
        $this->assertSame(20, $this->inventoryA->fresh()->quantity);
        $this->assertSame(0, InventoryMovement::query()->withoutGlobalScopes()->count());
    }

    public function test_an_expired_draft_is_expired_when_submitted(): void
    {
        $action = $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 19);
        $action->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->submit($action, $this->merchantA));
            $this->fail('An expired draft should not be submitted.');
        } catch (BusinessRuleViolation) {
            // expected
        }

        $this->assertSame(AiActionStatus::Expired, $action->fresh()->status);
    }

    public function test_revoking_the_executor_permission_between_approval_and_execution_stops_the_run(): void
    {
        $approver = $this->customMember(
            $this->tenantA,
            ['dashboard.view', 'ai.use', 'ai.approve', 'inventory.manage'],
            $this->merchantA,
        );

        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 7));
        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $approver, true));

        $role = $approver->roleAssignments()->where('tenant_id', $this->tenantA->getKey())->firstOrFail()->role;
        app(RoleService::class)->syncPermissions(
            $role,
            ['dashboard.view', 'ai.use', 'ai.approve'],
            $this->merchantA,
            $this->tenantA,
        );

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $approver));
            $this->fail('Execution should require the executor permission in the current role set.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertStringContainsString('inventory.manage', $exception->getMessage());
        }

        $this->assertSame(AiActionStatus::Approved, $action->fresh()->status);
        $this->assertSame(20, $this->inventoryA->fresh()->quantity);
    }

    public function test_a_proposal_with_another_tenants_record_is_refused(): void
    {
        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->propose(
                'inventory',
                'inventory.adjust',
                [
                    'store_id' => $this->storeA->getKey(),
                    'product_variant_id' => $this->variantB->getKey(),
                    'quantity' => 5,
                ],
                $this->merchantA,
            ));
            $this->fail('A product variant from another tenant must not resolve.');
        } catch (BusinessRuleViolation) {
            // expected
        }

        $this->assertSame(0, AiAction::query()->withoutGlobalScopes()->count());
    }

    public function test_another_tenants_action_is_not_visible_or_approvable_over_http(): void
    {
        $foreign = $this->submit(
            $this->proposeInventory($this->tenantB, $this->merchantB, $this->inventoryB(), 9),
            $this->tenantB,
            $this->merchantB,
        );

        $this->actingAs($this->merchantA)
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->getJson('/api/v1/tenant/ai/actions/'.$foreign->getKey())
            ->assertNotFound();

        $this->actingAs($this->merchantA)
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->postJson('/api/v1/tenant/ai/actions/'.$foreign->getKey().'/approve', ['confirmed' => true])
            ->assertNotFound();
    }

    public function test_the_route_permissions_are_enforced_before_the_service(): void
    {
        $withoutAi = $this->customMember($this->tenantA, ['inventory.manage'], $this->merchantA);
        $withoutApprove = $this->customMember($this->tenantA, ['ai.use', 'inventory.manage'], $this->merchantA);

        $this->actingAs($withoutAi)
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->getJson('/api/v1/tenant/ai/actions')
            ->assertForbidden();

        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 13));

        $this->actingAs($withoutApprove)
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->postJson('/api/v1/tenant/ai/actions/'.$action->getKey().'/approve', ['confirmed' => true])
            ->assertForbidden();
    }

    public function test_ai_use_alone_cannot_propose_an_action_without_the_executor_permission(): void
    {
        $assistant = $this->customMember($this->tenantA, ['ai.use'], $this->merchantA);

        $this->actingAs($assistant)
            ->withHeader('X-Tenant', $this->tenantA->slug)
            ->postJson('/api/v1/tenant/ai/actions', [
                'use_case' => 'inventory',
                'action_type' => 'inventory.adjust',
                'summary' => 'Set stock to five',
                'payload' => [
                    'store_id' => $this->storeA->getKey(),
                    'product_variant_id' => $this->variantA->getKey(),
                    'quantity' => 5,
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You do not have the "inventory.manage" permission.');

        $this->assertSame(0, AiAction::query()->withoutGlobalScopes()->count());
    }

    public function test_a_plan_restriction_blocks_a_disallowed_classification(): void
    {
        $this->tenantA->forceFill([
            'settings' => ['ai_governance' => ['allowed_classifications' => [AiActionClassification::DraftCreation->value]]],
        ])->save();

        try {
            $this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 10);
            $this->fail('The plan allows draft creation only, so a reversible change must be refused.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertSame('ai_action_plan_restricted', $exception->errorCode());
        }

        $this->assertSame(0, AiAction::query()->withoutGlobalScopes()->count());
    }

    public function test_an_order_is_created_once_even_when_execution_is_replayed(): void
    {
        $action = $this->propose(
            $this->tenantA,
            $this->merchantA,
            'orders',
            'order.create',
            [
                'store_id' => $this->storeA->getKey(),
                'customer_name' => 'Nadia Hassan',
                'customer_phone' => '+15550001111',
                'items' => [['product_variant_id' => $this->variantA->getKey(), 'quantity' => 2]],
            ],
        );

        $action = $this->submit($action);
        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, $this->inventoryA->fresh()->reserved_quantity);
    }

    public function test_an_order_proposal_fails_if_a_price_changed_after_review(): void
    {
        $action = $this->propose(
            $this->tenantA,
            $this->merchantA,
            'orders',
            'order.create',
            [
                'store_id' => $this->storeA->getKey(),
                'customer_name' => 'Omar Ali',
                'items' => [['product_variant_id' => $this->variantA->getKey(), 'quantity' => 1]],
            ],
        );

        $this->withinTenant($this->tenantA, fn () => $this->variantA->forceFill(['price' => 99])->save());
        $action = $this->submit($action);
        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));
            $this->fail('A changed price must invalidate the reviewed order.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertStringContainsString('changed', $exception->getMessage());
        }

        $this->assertSame(AiActionStatus::Failed, $action->fresh()->status);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    public function test_an_outbound_message_is_written_once_even_when_execution_is_replayed(): void
    {
        $conversation = $this->withinTenant($this->tenantA, function (): Conversation {
            $customer = Customer::factory()->create([
                'tenant_id' => $this->tenantA->getKey(),
                'store_id' => $this->storeA->getKey(),
            ]);

            return Conversation::factory()->create([
                'tenant_id' => $this->tenantA->getKey(),
                'store_id' => $this->storeA->getKey(),
                'customer_id' => $customer->getKey(),
                'channel' => 'web',
                'status' => ConversationStatus::Open,
            ]);
        });

        $action = $this->propose(
            $this->tenantA,
            $this->merchantA,
            'conversations',
            'conversation.send_message',
            ['conversation_id' => $conversation->getKey(), 'body' => 'Your order is on its way.'],
        );

        $action = $this->submit($action);
        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));
        $this->withinTenant($this->tenantA, fn () => $this->actions->execute($action, $this->merchantA));

        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->getKey())
            ->where('direction', 'outbound')
            ->count());
    }

    public function test_separate_approver_policy_can_refuse_self_approval(): void
    {
        config()->set('ai.governance.require_separate_approver', true);

        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, 16));

        try {
            $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));
            $this->fail('The proposer must not approve when separation is enabled.');
        } catch (BusinessRuleViolation $exception) {
            $this->assertStringContainsString('someone other than', $exception->getMessage());
        }
    }

    private function proposeInventory(
        Tenant $tenant,
        User $actor,
        Inventory $inventory,
        int $quantity,
        ?string $key = null,
    ): AiAction {
        return $this->propose($tenant, $actor, 'inventory', 'inventory.adjust', [
            'store_id' => $inventory->store_id,
            'product_variant_id' => $inventory->product_variant_id,
            'quantity' => $quantity,
            'note' => 'AI-assisted stock take',
        ], $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function propose(
        Tenant $tenant,
        User $actor,
        string $useCase,
        string $actionType,
        array $payload,
        ?string $key = null,
    ): AiAction {
        return $this->withinTenant($tenant, fn (): AiAction => $this->actions->propose(
            $useCase,
            $actionType,
            $payload,
            $actor,
            'Test proposal',
            $key,
        ));
    }

    private function submit(AiAction $action, ?Tenant $tenant = null, ?User $actor = null): AiAction
    {
        $tenant ??= $this->tenantA;
        $actor ??= $this->merchantA;

        return $this->withinTenant($tenant, fn (): AiAction => $this->actions->submit($action, $actor));
    }

    private function approvedInventoryAction(int $quantity): AiAction
    {
        $action = $this->submit($this->proposeInventory($this->tenantA, $this->merchantA, $this->inventoryA, $quantity));

        $this->withinTenant($this->tenantA, fn () => $this->actions->approve($action, $this->merchantA, true));

        return $action->refresh();
    }

    private function inventoryB(): Inventory
    {
        return $this->withinTenant($this->tenantB, fn (): Inventory => Inventory::query()
            ->where('store_id', $this->storeB->getKey())
            ->where('product_variant_id', $this->variantB->getKey())
            ->firstOrFail());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function customMember(Tenant $tenant, array $permissions, User $actor): User
    {
        $role = app(RoleService::class)->createRole(
            'Test role '.Str::random(6),
            RoleScope::Tenant,
            null,
            $permissions,
            $tenant,
            $actor,
        );

        $user = User::factory()->create();
        $user->tenants()->attach($tenant->getKey(), ['is_owner' => false]);
        app(RoleService::class)->assign($user, $role, $tenant);

        return $user->fresh();
    }

    private function storeFor(Tenant $tenant): Store
    {
        return Store::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->firstOrFail();
    }
}
