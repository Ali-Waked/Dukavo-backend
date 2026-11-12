<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AutomationInstallationStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use App\Events\Automation\AutomationEvent;
use App\Events\Automation\InventoryOutOfStock;
use App\Events\Automation\InventoryRestocked;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\AutomationTask;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateInstallation;
use App\Models\AutomationTemplateVersion;
use App\Models\Inventory;
use App\Models\NotificationDelivery;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationScheduleRunner;
use App\Services\Automation\AutomationTemplateService;
use Database\Seeders\AutomationTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The template library is platform-owned; every executable copy is tenant-owned.
 *
 * These tests pin the operational promises: the catalogue is idempotent and
 * versioned, an installation starts paused, merchant customisations survive a
 * template update until explicitly replaced, scheduled and email actions are
 * honest, and a failed run can be retried without duplicating work.
 */
class AutomationTemplateTest extends TestCase
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

    public function test_the_built_in_catalogue_is_idempotent_and_keeps_immutable_version_snapshots(): void
    {
        $this->seed(AutomationTemplateSeeder::class);

        $count = AutomationTemplate::query()->count();
        $versions = AutomationTemplateVersion::query()->count();

        $this->assertGreaterThanOrEqual(13, $count);
        $this->assertSame($count, $versions);

        $template = AutomationTemplate::query()->where('key', 'large-order-review')->firstOrFail();
        $template->forceFill(['name' => 'Locally renamed template'])->save();

        $this->seed(AutomationTemplateSeeder::class);

        $this->assertSame($count, AutomationTemplate::query()->count());
        $this->assertSame($versions, AutomationTemplateVersion::query()->count());
        $this->assertSame(
            'Review large orders',
            AutomationTemplateVersion::query()
                ->where('automation_template_id', $template->getKey())
                ->where('version', $template->current_version)
                ->sole()
                ->name,
        );
    }

    public function test_a_merchant_can_install_a_published_template_as_a_paused_rule_and_remove_it(): void
    {
        $template = $this->template([
            'name' => 'Review large orders',
            'configuration_schema' => [
                [
                    'key' => 'review_label',
                    'label' => 'Task title',
                    'type' => 'text',
                    'required' => true,
                    'default' => 'Review a large order',
                ],
            ],
            'actions' => [
                [
                    'type' => 'create_task',
                    'config' => ['title' => '{{ config.review_label }} {{ order_number }}'],
                ],
            ],
        ]);

        $response = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
                'configuration' => ['review_label' => 'Check this large order'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', AutomationInstallationStatus::Installed->value)
            ->assertJsonPath('data.installed_version', '1.0.0')
            ->assertJsonPath('data.rule.status', AutomationRuleStatus::Paused->value)
            ->assertJsonPath('data.template.current_version', '1.0.0');

        $installationId = (int) $response->json('data.id');

        $rule = AutomationRule::query()->withoutGlobalScopes()
            ->where('automation_template_id', $template->getKey())
            ->sole();

        $this->assertSame(AutomationRuleStatus::Paused, $rule->status);
        $this->assertSame($this->tenant->getKey(), $rule->tenant_id);
        $this->assertSame('Check this large order {{ order_number }}', $rule->actions[0]['config']['title']);
        $this->assertFalse($rule->template_customized);
        $this->assertSame('1.0.0', $rule->template_version);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
            ])
            ->assertStatus(409);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.installations.enable', $installationId))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationInstallationStatus::Installed->value)
            ->assertJsonPath('data.rule.status', AutomationRuleStatus::Active->value);

        $this->assertSame(AutomationRuleStatus::Active, $rule->fresh()->status);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.installations.disable', $installationId))
            ->assertOk()
            ->assertJsonPath('data.rule.status', AutomationRuleStatus::Paused->value);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->deleteJson(route('api.tenant.automation.installations.destroy', $installationId))
            ->assertOk();

        $this->assertSame(
            AutomationInstallationStatus::Removed,
            AutomationTemplateInstallation::query()->withoutGlobalScopes()->findOrFail($installationId)->status,
        );
        $this->assertSoftDeleted('automation_rules', ['id' => $rule->getKey()]);
    }

    public function test_a_customised_installation_is_not_overwritten_until_the_merchant_confirms(): void
    {
        $template = $this->template();
        $actor = $this->platformUser();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
            ])
            ->assertCreated();

        $installation = AutomationTemplateInstallation::query()
            ->withoutGlobalScopes()
            ->where('automation_template_id', $template->getKey())
            ->sole();

        $rule = AutomationRule::query()->withoutGlobalScopes()->findOrFail($installation->automation_rule_id);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->patchJson(route('api.tenant.automation.rules.update', $rule), [
                'actions' => [
                    ['type' => 'create_task', 'config' => ['title' => 'Merchant customised title']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.template_customized', true);

        $this->travel(1)->minutes();

        app(AutomationTemplateService::class)->update($template, [
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Platform template v1.1 title']],
            ],
            'version' => '1.1.0',
            'changelog' => 'Clearer task title.',
        ], $actor);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.automation.installations.preview', $installation))
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.customized', true)
            ->assertJsonPath('data.current_version', '1.1.0');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.installations.apply-update', $installation))
            ->assertStatus(409);

        $this->assertSame('Merchant customised title', $rule->fresh()->actions[0]['config']['title']);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.installations.apply-update', $installation), ['force' => true])
            ->assertOk()
            ->assertJsonPath('data.installed_version', '1.1.0')
            ->assertJsonPath('data.template_customized', false);

        $updated = $rule->fresh();

        $this->assertSame('Platform template v1.1 title', $updated->actions[0]['config']['title']);
        $this->assertSame('1.1.0', $updated->template_version);
        $this->assertFalse($updated->template_customized);
    }

    public function test_installations_are_isolated_between_tenants(): void
    {
        $template = $this->template();
        $otherTenant = $this->tenantWithStore();
        $otherMerchant = $this->memberOf($otherTenant, 'merchant');

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
            ])
            ->assertCreated();

        $installation = AutomationTemplateInstallation::query()->withoutGlobalScopes()->sole();

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.installations.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($otherMerchant)
            ->withHeader('X-Tenant', $otherTenant->slug)
            ->getJson(route('api.tenant.automation.installations.show', $installation))
            ->assertNotFound();
    }

    public function test_a_scheduled_rule_is_dispatched_once_per_period(): void
    {
        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => null,
            'trigger' => AutomationTrigger::ScheduleWeekly,
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Weekly review {{ week_start }}']],
            ],
            'created_by' => $this->merchant->getKey(),
        ]));

        $stats = app(AutomationScheduleRunner::class)->run('weekly');

        $this->assertGreaterThanOrEqual(1, $stats['events']);

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame('scheduled', $run->source);
        $this->assertStringStartsWith('schedule.weekly:', $run->event_key);

        $this->assertSame(1, AutomationTask::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->count());

        app(AutomationScheduleRunner::class)->run('weekly');

        $this->assertSame(1, AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->count());
    }

    public function test_an_out_of_stock_event_runs_an_inventory_rule_and_carries_context(): void
    {
        $this->stockedProduct($this->tenant, $this->store, 0);

        $inventory = Inventory::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->getKey())
            ->where('store_id', $this->store->getKey())
            ->firstOrFail();

        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'trigger' => AutomationTrigger::InventoryOutOfStock,
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Restock {{ product_name }}']],
            ],
            'created_by' => $this->merchant->getKey(),
        ]));

        $event = InventoryOutOfStock::fromInventory($inventory, 9101, 2);

        $this->assertSame(AutomationTrigger::InventoryOutOfStock, $event->trigger());
        $this->assertSame((int) $this->store->getKey(), $event->storeId());
        $this->assertSame((int) $inventory->getKey(), $event->context()['inventory_id']);
        $this->assertSame(2, $event->context()['previous_available']);

        $restocked = InventoryRestocked::fromInventory($inventory, 0, 9102);
        $this->assertSame(AutomationTrigger::InventoryRestocked, $restocked->trigger());
        $this->assertSame(0, $restocked->context()['previous_available']);

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)->handle($event));

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame(1, AutomationTask::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->count());
    }

    public function test_a_customer_email_without_a_destination_is_recorded_as_skipped(): void
    {
        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'trigger' => AutomationTrigger::OrderCreated,
            'actions' => [
                [
                    'type' => 'send_customer_email',
                    'config' => ['subject' => 'About {{ order_number }}', 'body' => 'Thanks for your order.'],
                ],
            ],
            'created_by' => $this->merchant->getKey(),
        ]));

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)
            ->handle($this->event('order.created:9101', [
                'order_id' => 9101,
                'order_number' => 'ORD-091001',
                'store_id' => $this->store->getKey(),
            ])));

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Skipped, $run->status);
        $this->assertSame('skipped', $run->actions[0]['status']);
        $this->assertSame('customer_email_missing', $run->actions[0]['data']['reason']);
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    public function test_a_partially_failed_run_can_be_retried_without_repeating_completed_actions(): void
    {
        $rule = $this->withinTenant($this->tenant, fn (): AutomationRule => AutomationRule::factory()->active()->create([
            'tenant_id' => $this->tenant->getKey(),
            'store_id' => $this->store->getKey(),
            'trigger' => AutomationTrigger::OrderCreated,
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Pack {{ order_number }}']],
                ['type' => 'notify_team', 'config' => ['message' => '']],
            ],
            'created_by' => $this->merchant->getKey(),
        ]));

        $this->withinTenant($this->tenant, fn () => app(AutomationEngine::class)
            ->handle($this->event('order.created:9102', [
                'order_id' => 9102,
                'order_number' => 'ORD-091002',
                'store_id' => $this->store->getKey(),
            ])));

        $run = AutomationRun::query()->withoutGlobalScopes()
            ->where('automation_rule_id', $rule->getKey())
            ->sole();

        $this->assertSame(AutomationRunStatus::Partial, $run->status);
        $this->assertSame(1, AutomationTask::query()->withoutGlobalScopes()->count());

        $rule->forceFill([
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => 'Pack {{ order_number }}']],
                ['type' => 'notify_team', 'config' => ['message' => 'Order {{ order_number }} needs packing.']],
            ],
        ])->save();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.runs.retry', $run))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationRunStatus::Completed->value)
            ->assertJsonPath('data.attempts', 2)
            ->assertJsonPath('data.source', 'retry');

        $this->assertSame(1, AutomationTask::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, AutomationRun::query()->withoutGlobalScopes()->count());

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.runs.retry', $run))
            ->assertStatus(409);
    }

    public function test_platform_operators_author_and_publish_templates_without_a_tenant(): void
    {
        $operator = $this->platformUser();

        $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.meta'))
            ->assertOk()
            ->assertJsonPath('data.triggers.0.key', AutomationTrigger::cases()[0]->value)
            ->assertJsonFragment(['key' => 'send_customer_whatsapp', 'available' => false])
            ->assertJsonFragment(['key' => 'send_customer_email']);

        $created = $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.store'), [
                'key' => 'platform-review',
                'name' => 'Platform review',
                'description' => 'Created by the platform team.',
                'category' => 'orders',
                'trigger' => AutomationTrigger::OrderCreated->value,
                'actions' => [
                    ['type' => 'create_task', 'config' => ['title' => 'Review {{ order_number }}']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', AutomationTemplateStatus::Draft->value)
            ->assertJsonPath('data.current_version', '1.0.0');

        $templateId = (int) $created->json('data.id');

        $this->actingAs($operator)
            ->patchJson(route('api.platform.automation-templates.update', $templateId), [
                'name' => 'Platform review (renamed)',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Platform review (renamed)')
            ->assertJsonPath('data.current_version', '1.0.0');

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.publish', $templateId))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationTemplateStatus::Active->value);

        $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.versions', $templateId))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($operator)
            ->deleteJson(route('api.platform.automation-templates.destroy', $templateId))
            ->assertNoContent();
    }

    public function test_a_platform_operator_archives_an_active_template_without_losing_its_history(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Archive me']);
        $versionCount = AutomationTemplateVersion::query()->count();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertOk()
            ->assertJsonPath('data.id', $template->getKey())
            ->assertJsonPath('data.status', AutomationTemplateStatus::Archived->value);

        $template->refresh();

        $this->assertSame(AutomationTemplateStatus::Archived, $template->status);
        $this->assertSame($versionCount, AutomationTemplateVersion::query()->count());

        // A repeated archive is an idempotent no-op, not an error and not a
        // second lifecycle record.
        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationTemplateStatus::Archived->value);

        $this->assertSame(1, AutomationTemplate::query()->whereKey($template->getKey())->count());
    }

    public function test_an_archived_template_disappears_from_the_merchant_library(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Hidden when archived']);

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertOk();

        $listing = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.automation.templates.index'))
            ->assertOk()
            ->json('data');

        $ids = collect($listing)->pluck('id');

        $this->assertNotContains($template->getKey(), $ids);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.automation.templates.show', $template))
            ->assertNotFound();
    }

    public function test_an_archived_template_appears_in_the_platform_archived_listing_only(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Archived listing']);

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertOk();

        $archived = $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.index', ['status' => AutomationTemplateStatus::Archived->value]))
            ->assertOk()
            ->json('data');

        $active = $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.index', ['status' => AutomationTemplateStatus::Active->value]))
            ->assertOk()
            ->json('data');

        $this->assertContains($template->getKey(), collect($archived)->pluck('id'));
        $this->assertNotContains($template->getKey(), collect($active)->pluck('id'));
    }

    public function test_a_platform_operator_restores_an_archived_template_without_changing_its_definition(): void
    {
        $operator = $this->platformUser();
        $template = $this->template([
            'name' => 'Restore me',
            'current_version' => '1.2.0',
            'configuration_schema' => [
                ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'default' => 'Keep me'],
            ],
        ]);
        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $before = $template->fresh();
        $versionCount = AutomationTemplateVersion::query()->count();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk()
            ->assertJsonPath('data.id', $before->getKey())
            ->assertJsonPath('data.status', AutomationTemplateStatus::Active->value)
            ->assertJsonPath('data.current_version', '1.2.0');

        $after = $template->fresh();

        $this->assertSame($before->getKey(), $after->getKey());
        $this->assertSame($before->key, $after->key);
        $this->assertSame($before->current_version, $after->current_version);
        $this->assertSame($before->actions, $after->actions);
        $this->assertSame($before->configuration_schema, $after->configuration_schema);
        $this->assertSame($versionCount, AutomationTemplateVersion::query()->count());
        $this->assertSame(1, AutomationTemplate::query()->whereKey($before->getKey())->count());
    }

    public function test_a_restored_template_reappears_in_the_merchant_library(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Visible again']);
        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk();

        $listing = $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson(route('api.tenant.automation.templates.index'))
            ->assertOk()
            ->json('data');

        $this->assertContains($template->getKey(), collect($listing)->pluck('id'));
    }

    public function test_a_restored_template_leaves_the_archived_listing(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Movable']);
        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk();

        $archived = $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.index', ['status' => AutomationTemplateStatus::Archived->value]))
            ->assertOk()
            ->json('data');

        $active = $this->actingAs($operator)
            ->getJson(route('api.platform.automation-templates.index', ['status' => AutomationTemplateStatus::Active->value]))
            ->assertOk()
            ->json('data');

        $this->assertNotContains($template->getKey(), collect($archived)->pluck('id'));
        $this->assertContains($template->getKey(), collect($active)->pluck('id'));
    }

    public function test_a_restored_template_can_be_installed_again(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Installable after restore']);
        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
            ])
            ->assertStatus(409);

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', AutomationInstallationStatus::Installed->value);
    }

    public function test_existing_merchant_installations_are_unchanged_by_archive_and_restore(): void
    {
        $operator = $this->platformUser();
        $template = $this->template([
            'name' => 'Keep my installation',
            'actions' => [
                ['type' => 'create_task', 'config' => ['title' => '{{ config.label }} {{ order_number }}']],
            ],
            'configuration_schema' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
            ],
        ]);

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.templates.install', $template), [
                'store_id' => $this->store->getKey(),
                'configuration' => ['label' => 'Custom merchant label'],
            ])
            ->assertCreated();

        $installation = AutomationTemplateInstallation::query()
            ->withoutGlobalScopes()
            ->where('automation_template_id', $template->getKey())
            ->sole();
        $rule = AutomationRule::query()->withoutGlobalScopes()
            ->whereKey($installation->automation_rule_id)
            ->sole();

        $this->actingAs($this->merchant)
            ->withHeader('X-Tenant', $this->tenant->slug)
            ->postJson(route('api.tenant.automation.installations.enable', $installation->getKey()))
            ->assertOk();

        $before = $installation->fresh();
        $ruleBefore = $rule->fresh();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertOk();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk();

        $after = $installation->fresh();
        $ruleAfter = $rule->fresh();

        $this->assertSame($before->getKey(), $after->getKey());
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->installed_version, $after->installed_version);
        $this->assertSame($before->automation_rule_id, $after->automation_rule_id);
        $this->assertSame($ruleBefore->status, $ruleAfter->status);
        $this->assertSame($ruleBefore->actions, $ruleAfter->actions);
        $this->assertSame('Custom merchant label {{ order_number }}', $ruleAfter->actions[0]['config']['title']);
        $this->assertSame(1, AutomationTemplateInstallation::query()->withoutGlobalScopes()->where('automation_template_id', $template->getKey())->count());
        $this->assertSame(1, AutomationRule::query()->withoutGlobalScopes()->where('automation_template_id', $template->getKey())->count());
    }

    public function test_merchants_cannot_archive_or_restore_templates(): void
    {
        $template = $this->template(['name' => 'Protected']);

        $this->actingAs($this->merchant)
            ->postJson(route('api.platform.automation-templates.archive', $template))
            ->assertForbidden();

        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $this->actingAs($this->merchant)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertForbidden();

        $this->assertSame(AutomationTemplateStatus::Archived, $template->fresh()->status);
    }

    public function test_repeated_restore_requests_do_not_create_duplicates(): void
    {
        $operator = $this->platformUser();
        $template = $this->template(['name' => 'Restore twice']);
        $template->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $count = AutomationTemplate::query()->count();
        $versionCount = AutomationTemplateVersion::query()->count();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationTemplateStatus::Active->value);

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $template))
            ->assertOk()
            ->assertJsonPath('data.status', AutomationTemplateStatus::Active->value);

        $this->assertSame($count, AutomationTemplate::query()->count());
        $this->assertSame($versionCount, AutomationTemplateVersion::query()->count());
        $this->assertSame(1, AutomationTemplate::query()->where('key', $template->key)->count());
    }

    public function test_invalid_template_lifecycle_transitions_are_rejected(): void
    {
        $operator = $this->platformUser();
        $draft = $this->template(['name' => 'Draft template'])->forceFill([
            'status' => AutomationTemplateStatus::Draft,
        ]);
        $draft->save();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.archive', $draft))
            ->assertStatus(409);

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.restore', $draft))
            ->assertStatus(409);

        $archived = $this->template(['name' => 'Archived template']);
        $archived->forceFill(['status' => AutomationTemplateStatus::Archived])->save();

        $this->actingAs($operator)
            ->postJson(route('api.platform.automation-templates.publish', $archived))
            ->assertStatus(409);

        $this->assertSame(AutomationTemplateStatus::Draft, $draft->fresh()->status);
        $this->assertSame(AutomationTemplateStatus::Archived, $archived->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function template(array $attributes = []): AutomationTemplate
    {
        return AutomationTemplate::factory()->create($attributes);
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
