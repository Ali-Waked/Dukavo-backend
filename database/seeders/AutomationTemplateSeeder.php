<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * Publishes the built-in automation template library.
 *
 * The seeder is idempotent and version-aware: a template is only rewritten when
 * the catalogue declares a newer version. Merchant installations are separate
 * rows, so updating a template here never changes an installed rule until the
 * merchant accepts the update.
 *
 * Every trigger and action below is implemented by the delivery layer. The
 * WhatsApp action is deliberately absent because business-initiated WhatsApp
 * messaging is not available in this product yet.
 */
class AutomationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            $this->publish($template);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publish(array $definition): void
    {
        $version = (string) $definition['version'];
        unset($definition['version'], $definition['changelog']);

        $existing = AutomationTemplate::query()
            ->where('key', $definition['key'])
            ->first();

        if (! $existing instanceof AutomationTemplate) {
            $template = AutomationTemplate::query()->create($definition + [
                'status' => AutomationTemplateStatus::Active,
                'current_version' => $version,
            ]);
        } elseif (version_compare($version, $existing->current_version, '>')) {
            $existing->fill($definition + [
                'status' => AutomationTemplateStatus::Active,
                'current_version' => $version,
            ])->save();

            $template = $existing->refresh();
        } else {
            $template = $existing;
        }

        $this->snapshot($template, $version);
    }

    private function snapshot(AutomationTemplate $template, string $version): void
    {
        if (version_compare($version, $template->current_version, '!=')) {
            return;
        }

        $snapshot = [
            'name' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'trigger' => $template->trigger->value,
            'conditions' => $template->conditions ?? [],
            'actions' => $template->actions ?? [],
            'configuration_schema' => $template->configuration_schema ?? [],
        ];

        // A version snapshot is immutable: re-running the seeder must not
        // rewrite a published definition, even when the template row has been
        // edited in place. A change belongs in a new version.
        AutomationTemplateVersion::query()->firstOrCreate(
            [
                'automation_template_id' => $template->getKey(),
                'version' => $version,
            ],
            $snapshot + [
                'checksum' => hash('sha256', (string) json_encode($snapshot)),
                'changelog' => 'Built-in template version '.$version.'.',
                'created_by' => null,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'key' => 'large-order-review',
                'name' => 'Review large orders',
                'description' => 'Creates an internal task when a new order is above the value you choose, so a big sale is packed and checked deliberately.',
                'category' => 'orders',
                'trigger' => AutomationTrigger::OrderCreated->value,
                'conditions' => [
                    ['field' => 'grand_total', 'operator' => 'greater_than_or_equal', 'value' => '{{ config.threshold }}'],
                ],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => 'Total: {{ grand_total }} {{ currency }}. Customer: {{ customer_name }}.',
                            'priority' => '{{ config.priority }}',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'threshold',
                        'label' => 'Minimum order total',
                        'type' => 'number',
                        'required' => true,
                        'default' => 500,
                        'min' => 0,
                        'help' => 'Only orders at or above this value create a task.',
                    ],
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Review large order {{ order_number }}',
                    ],
                    [
                        'key' => 'priority',
                        'label' => 'Task priority',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'high',
                        'options' => [
                            ['value' => 'normal', 'label' => 'Normal'],
                            ['value' => 'high', 'label' => 'High'],
                            ['value' => 'urgent', 'label' => 'Urgent'],
                        ],
                    ],
                ],
                'icon' => 'receipt',
                'sort_order' => 10,
                'version' => '1.0.0',
            ],
            [
                'key' => 'order-paid-notify',
                'name' => 'Celebrate a confirmed payment',
                'description' => 'Notifies the team when an order payment is confirmed, so fulfilment starts quickly.',
                'category' => 'orders',
                'trigger' => AutomationTrigger::OrderPaid->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'notify_team',
                        'config' => ['message' => '{{ config.message }}'],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'message',
                        'label' => 'Team message',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => 'Order {{ order_number }} is paid ({{ grand_total }} {{ currency }}). Start fulfilment.',
                    ],
                ],
                'icon' => 'check-circle',
                'sort_order' => 20,
                'version' => '1.0.0',
            ],
            [
                'key' => 'delivered-follow-up',
                'name' => 'Follow up after delivery',
                'description' => 'Sends the customer a short thank-you and review request after an order is delivered.',
                'category' => 'orders',
                'trigger' => AutomationTrigger::OrderDelivered->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'send_customer_email',
                        'config' => [
                            'subject' => '{{ config.subject }}',
                            'body' => '{{ config.body }}',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'subject',
                        'label' => 'Email subject',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'How was order {{ order_number }}?',
                    ],
                    [
                        'key' => 'body',
                        'label' => 'Email body',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => 'Hi {{ customer_name }}, your order {{ order_number }} has been delivered. We would love to hear how it went.',
                    ],
                ],
                'icon' => 'mail',
                'sort_order' => 30,
                'version' => '1.0.0',
            ],
            [
                'key' => 'abandoned-order-follow-up',
                'name' => 'Follow up an unpaid order',
                'description' => 'Finds orders that are still unpaid after the age you choose, then emails the customer and opens a follow-up task.',
                'category' => 'orders',
                'trigger' => AutomationTrigger::OrderAbandoned->value,
                'conditions' => [
                    ['field' => 'age_hours', 'operator' => 'greater_than_or_equal', 'value' => '{{ config.min_age_hours }}'],
                ],
                'actions' => [
                    [
                        'type' => 'send_customer_email',
                        'config' => [
                            'subject' => '{{ config.subject }}',
                            'body' => '{{ config.body }}',
                        ],
                    ],
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => 'Follow up unpaid order {{ order_number }}',
                            'description' => 'The order has been unpaid for {{ age_hours }} hours.',
                            'priority' => 'normal',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'min_age_hours',
                        'label' => 'Minimum age in hours',
                        'type' => 'integer',
                        'required' => true,
                        'default' => 24,
                        'min' => 1,
                        'max' => 720,
                    ],
                    [
                        'key' => 'subject',
                        'label' => 'Email subject',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Still thinking about order {{ order_number }}?',
                    ],
                    [
                        'key' => 'body',
                        'label' => 'Email body',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => 'Hi {{ customer_name }}, your order {{ order_number }} is waiting for payment. Reply if you need help completing it.',
                    ],
                ],
                'icon' => 'clock',
                'sort_order' => 40,
                'version' => '1.0.0',
            ],
            [
                'key' => 'low-stock-alert',
                'name' => 'Alert the team on low stock',
                'description' => 'Notifies the team when a variant reaches its low-stock threshold, before it sells out.',
                'category' => 'inventory',
                'trigger' => AutomationTrigger::InventoryLowStock->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'notify_team',
                        'config' => ['message' => '{{ config.message }}'],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'message',
                        'label' => 'Team message',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => '{{ product_name }} ({{ sku }}) is low: {{ available }} available at {{ store_name }}.',
                    ],
                ],
                'icon' => 'trending-down',
                'sort_order' => 50,
                'version' => '1.0.0',
            ],
            [
                'key' => 'out-of-stock-task',
                'name' => 'Open a restock task when sold out',
                'description' => 'Creates an urgent task when a variant reaches zero available stock.',
                'category' => 'inventory',
                'trigger' => AutomationTrigger::InventoryOutOfStock->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => '{{ product_name }} ({{ sku }}) has no available stock at {{ store_name }}.',
                            'priority' => 'urgent',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Restock {{ product_name }}',
                    ],
                ],
                'icon' => 'package-x',
                'sort_order' => 60,
                'version' => '1.0.0',
            ],
            [
                'key' => 'restock-notify',
                'name' => 'Announce a restock internally',
                'description' => 'Notifies the team when a sold-out or low variant is back above its threshold.',
                'category' => 'inventory',
                'trigger' => AutomationTrigger::InventoryRestocked->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'notify_team',
                        'config' => ['message' => '{{ config.message }}'],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'message',
                        'label' => 'Team message',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => '{{ product_name }} ({{ sku }}) is back in stock: {{ available }} available.',
                    ],
                ],
                'icon' => 'package-check',
                'sort_order' => 70,
                'version' => '1.0.0',
            ],
            [
                'key' => 'new-customer-welcome',
                'name' => 'Welcome a new customer',
                'description' => 'Sends a welcome email when a customer record is created with an email address.',
                'category' => 'customers',
                'trigger' => AutomationTrigger::CustomerCreated->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'send_customer_email',
                        'config' => [
                            'subject' => '{{ config.subject }}',
                            'body' => '{{ config.body }}',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'subject',
                        'label' => 'Email subject',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Welcome, {{ customer_name }}',
                    ],
                    [
                        'key' => 'body',
                        'label' => 'Email body',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => 'Hi {{ customer_name }}, thanks for joining us. We are glad you are here.',
                    ],
                ],
                'icon' => 'user-plus',
                'sort_order' => 80,
                'version' => '1.0.0',
            ],
            [
                'key' => 'message-needs-reply',
                'name' => 'Turn a new message into a task',
                'description' => 'Creates a follow-up task when a customer message arrives, so nothing waits in the inbox.',
                'category' => 'conversations',
                'trigger' => AutomationTrigger::ConversationMessageReceived->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => 'Latest message: {{ preview }}',
                            'priority' => 'high',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Reply to {{ customer_name }}',
                    ],
                ],
                'icon' => 'message-square',
                'sort_order' => 90,
                'version' => '1.0.0',
            ],
            [
                'key' => 'conversation-handoff',
                'name' => 'Announce a conversation handoff',
                'description' => 'Notifies the team when a conversation is assigned to a teammate.',
                'category' => 'conversations',
                'trigger' => AutomationTrigger::ConversationAssigned->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'notify_team',
                        'config' => ['message' => '{{ config.message }}'],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'message',
                        'label' => 'Team message',
                        'type' => 'textarea',
                        'required' => true,
                        'default' => '{{ customer_name }} was assigned to {{ assigned_name }}.',
                    ],
                ],
                'icon' => 'users',
                'sort_order' => 100,
                'version' => '1.0.0',
            ],
            [
                'key' => 'recovery-outreach',
                'name' => 'Open a recovery task',
                'description' => 'Creates a task when a lost-sale opportunity above the value you choose is detected.',
                'category' => 'recovery',
                'trigger' => AutomationTrigger::RecoveryOpportunityDetected->value,
                'conditions' => [
                    ['field' => 'estimated_value', 'operator' => 'greater_than_or_equal', 'value' => '{{ config.min_value }}'],
                ],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => 'Estimated value: {{ estimated_value }} {{ currency }}. Reason: {{ reason }}.',
                            'priority' => 'high',
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'min_value',
                        'label' => 'Minimum estimated value',
                        'type' => 'number',
                        'required' => true,
                        'default' => 100,
                        'min' => 0,
                    ],
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Recover {{ customer_name }}\'s cart',
                    ],
                ],
                'icon' => 'heart-handshake',
                'sort_order' => 110,
                'version' => '1.0.0',
            ],
            [
                'key' => 'daily-sales-triage',
                'name' => 'Daily sales triage',
                'description' => 'Creates one daily task to review yesterday\'s orders and today\'s priorities.',
                'category' => 'scheduled',
                'trigger' => AutomationTrigger::ScheduleDaily->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => 'Daily review for {{ date }}.',
                            'priority' => 'normal',
                            'due_in_days' => 1,
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Daily sales review · {{ date }}',
                    ],
                ],
                'icon' => 'sun',
                'sort_order' => 120,
                'version' => '1.0.0',
            ],
            [
                'key' => 'weekly-ops-review',
                'name' => 'Weekly operations review',
                'description' => 'Creates one weekly task to review stock, fulfilment and open customer conversations.',
                'category' => 'scheduled',
                'trigger' => AutomationTrigger::ScheduleWeekly->value,
                'conditions' => [],
                'actions' => [
                    [
                        'type' => 'create_task',
                        'config' => [
                            'title' => '{{ config.task_title }}',
                            'description' => 'Operations review for ISO week {{ iso_week }} ({{ week_start }} to {{ week_end }}).',
                            'priority' => 'normal',
                            'due_in_days' => 2,
                        ],
                    ],
                ],
                'configuration_schema' => [
                    [
                        'key' => 'task_title',
                        'label' => 'Task title',
                        'type' => 'text',
                        'required' => true,
                        'default' => 'Weekly operations review · week {{ iso_week }}',
                    ],
                ],
                'icon' => 'calendar',
                'sort_order' => 130,
                'version' => '1.0.0',
            ],
        ];
    }
}
