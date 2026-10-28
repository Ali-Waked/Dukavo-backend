<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTaskStatus;
use App\Enums\AutomationTrigger;
use App\Events\Automation\AutomationEvent;
use App\Models\AiAction;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationTask;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Orders\OrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Automation rules are data, not code.
 *
 * The engine tests call the same service the queued listener calls, inside the
 * event's tenant context. They cover the failure modes that matter in
 * production: repeated delivery, cooldowns, mismatched conditions, tenant
 * boundaries and AI proposals that must remain proposals.
 */
class AutomationWorkflowTest extends TestCase
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
    }

    public function test_a_merchant_can_create_a_paused_rule_and_invalid_fields_are_refused(): void
    {
        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.rules.store'), [
                'name' => 'Large order follow-up',
                'trigger' => AutomationTrigger::OrderCreated->value,
                'store_id' => $this->store->getKey(),
                'conditions' => [
                    ['field' => 'grand_total', 'operator' => 'greater_than', 'value' => 100],
                ],
                'actions' => [
                    ['type' => 'notify_team', 'config' => ['message' => 'Order {{ order_number }} needs attention.']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Large order follow-up')
            ->assertJsonPath('data.status', AutomationRuleStatus::Paused->value)
            ->assertJsonPath('data.conditions.0.field', 'grand_total');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.rules.store'), [
                'name' => 'Impossible field',
                'trigger' => AutomationTrigger::OrderCreated->value,
                'conditions' => [
                    ['field' => 'inventory_id', 'operator' => 'equals', 'value' => 2],
                ],
                'actions' => [
                    ['type' => 'notify_team', 'config' => ['message' => 'This should not save.']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['conditions.0.field']);
    }

    public function test_staff_can_read_rules_and_complete_tasks_but_cannot_manage_rules(): void
    {
        $staff = $this->memberOf($this->tenant, 'staff');

        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'created_by' => $this->merchant->getKey(),
        ]));

        $task = $this->withinTenant($this->tenant, fn (): AutomationTask => AutomationTask::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'automation_rule_id' => $rule->getKey(),
            'store_id' => $this->store->getKey(),
            'assigned_to' => $staff->getKey(),
        ]));

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.automation.rules.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.rules.store'), [
                'name' => 'Staff rule',
                'trigger' => AutomationTrigger::OrderCreated->value,
                'actions' => [
                    ['type' => 'notify_team', 'config' => ['message' => 'Should be forbidden.']],
                ],
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.automation.tasks.update', $task), [
                'status' => AutomationTaskStatus::Completed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AutomationTaskStatus::Completed->value)
            ->assertJsonPath('data.completed_by', $staff->getKey());

        $this->assertSame($staff->getKey(), $task->fresh()->completed_by);
    }

    public function test_rules_runs_and_tasks_are_invisible_to_another_tenant(): void
    {
        $otherTenant = $this->tenantWithStore();
        $otherMerchant = $this->memberOf($otherTenant, 'merchant');

        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
        ]));

        $run = $this->withinTenant($this->tenant, fn (): AutomationRun => AutomationRun::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'automation_rule_id' => $rule->getKey(),
            'trigger' => AutomationTrigger::OrderCreated,
        ]));

        $task = $this->withinTenant($this->tenant, fn (): AutomationTask => AutomationTask::factory()->create([
            'tenant_id' => $this->tenant->getKey(),
            'automation_rule_id' => $rule->getKey(),
            'automation_run_id' => $run->getKey(),
        ]));

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.rules.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.rules.show', $rule))
            ->assertNotFound();

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.runs.show', $run))
            ->assertNotFound();

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.tasks.show', $task))
            ->assertNotFound();
    }

    public function test_matching_rule_creates_a_task_with_resolved_templates_and_replays_do_not_duplicate_it(): void
    {
        $rule = $this->rule([
            'name' => 'Pack large orders',
            'conditions' => [
                ['field' => 'grand_total', 'operator' => 'greater_than', 'value' => 100],
            ],
            'actions' => [
                [
                    'type' => 'create_task',
                    'config' => [
                        'title' => 'Pack order {{ order_number }}',
                        'description' => 'Customer: {{ customer_name }}',
                        'priority' => 'high',
                        'assigned_to' => $this->merchant->getKey(),
                        'due_in_days' => 2,
                    ],
                ],
            ],
        ]);

        $event = $this->event('order.created:9001', [
            'order_id' => 9001,
            'order_number' => 'ORD-090001',
            'grand_total' => 245.50,
            'customer_name' => 'Nadia Hassan',
            'store_id' => $this->store->getKey(),
        ]);

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($event));
        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($event));

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertTrue($run->matched);
        $this->assertSame(1, AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->count());

        $task = AutomationTask::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame('Pack order ORD-090001', $task->title);
        $this->assertSame('Customer: Nadia Hassan', $task->description);
        $this->assertSame('high', $task->priority);
        $this->assertSame($this->tenant->getKey(), $task->tenant_id);
        $this->assertNotNull($task->due_at);
        $this->assertSame(1, $rule->fresh()->run_count);
    }

    public function test_conditions_and_cooldowns_are_recorded_as_skipped_runs(): void
    {
        $rule = $this->rule([
            'conditions' => [
                ['field' => 'status', 'operator' => 'equals', 'value' => 'paid'],
            ],
            'cooldown_seconds' => 3600,
        ]);

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($this->event('order.created:9002', [
            'order_id' => 9002,
            'status' => 'pending',
            'store_id' => $this->store->getKey(),
        ])));

        $skipped = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Skipped, $skipped->status);
        $this->assertFalse($skipped->matched);
        $this->assertSame('conditions_not_met', $skipped->error);

        $rule->forceFill(['conditions' => []])->save();

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($this->event('order.created:9003', [
            'order_id' => 9003,
            'status' => 'paid',
            'store_id' => $this->store->getKey(),
        ])));

        $completed = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->where('event_key', 'order.created:9003')
            ->sole();

        $this->assertSame(AutomationRunStatus::Completed, $completed->status);

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($this->event('order.created:9004', [
            'order_id' => 9004,
            'status' => 'paid',
            'store_id' => $this->store->getKey(),
        ])));

        $cooldown = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->where('event_key', 'order.created:9004')
            ->sole();

        $this->assertSame(AutomationRunStatus::Skipped, $cooldown->status);
        $this->assertSame('cooldown_active', $cooldown->error);
    }

    public function test_an_ai_action_is_proposed_for_approval_and_not_executed(): void
    {
        $rule = $this->rule([
            'actions' => [
                [
                    'type' => 'propose_ai_action',
                    'config' => [
                        'action_type' => 'customer.create',
                        'summary' => 'Create a customer from an order',
                        'payload' => ['name' => '{{ customer_name }}'],
                    ],
                ],
            ],
        ]);

        $before = Customer::query()->withoutGlobalScopes()->count();

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($this->event('order.created:9005', [
            'order_id' => 9005,
            'order_number' => 'ORD-090005',
            'customer_name' => 'Omar Ali',
            'store_id' => $this->store->getKey(),
        ])));

        $proposal = AiAction::query()->withoutGlobalScopes()->sole();

        $this->assertSame('pending_approval', $proposal->status->value);
        $this->assertSame('customer.create', $proposal->action_type);
        $this->assertSame('Omar Ali', $proposal->payload['name']);
        $this->assertSame($before, Customer::query()->withoutGlobalScopes()->count());

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame('completed', $run->actions[0]['status']);
    }

    public function test_an_actual_created_order_runs_an_active_order_rule(): void
    {
        ['variant' => $variant] = $this->stockedProduct($this->tenant, $this->store);

        $rule = $this->rule([
            'actions' => [
                [
                    'type' => 'create_task',
                    'config' => ['title' => 'Review order {{ order_number }}'],
                ],
            ],
        ]);

        $order = $this->withinTenant($this->tenant, fn () => app(OrderService::class)->create([
            'store_id' => $this->store->getKey(),
            'items' => [['product_variant_id' => $variant->getKey(), 'quantity' => 1]],
        ]));

        $task = AutomationTask::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame('Review order '.$order->order_number, $task->title);
        $this->assertSame('order', $task->entity_type);
        $this->assertSame($order->getKey(), $task->entity_id);
        $this->assertSame(AutomationRunStatus::Completed, AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rule(array $attributes = []): AutomationRule
    {
        return $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create($attributes + [
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'trigger' => AutomationTrigger::OrderCreated,
            'created_by' => $this->merchant->getKey(),
        ]));
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function event(string $key, array $context): AutomationEvent
    {
        return new class($this->tenant->getKey(), $this->store->getKey(), $key, $context) implements AutomationEvent
        {
            /**
             * @param  array<string, scalar|null>  $context
             */
            public function __construct(
                private readonly int $tenant,
                private readonly int $store,
                private readonly string $key,
                private readonly array $context,
            ) {}

            public function tenantId(): int
            {
                return $this->tenant;
            }

            public function storeId(): ?int
            {
                return $this->store;
            }

            public function trigger(): AutomationTrigger
            {
                return AutomationTrigger::OrderCreated;
            }

            public function context(): array
            {
                return $this->context;
            }

            public function dedupeKey(): string
            {
                return $this->key;
            }
        };
    }
}
