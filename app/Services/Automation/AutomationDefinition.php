<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\AutomationConditionOperator;
use App\Enums\AutomationTrigger;
use App\Enums\OrderStatus;
use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;

/**
 * The catalogue of automation triggers, fields, operators and actions.
 *
 * The same definitions drive API validation and the frontend editor. A field
 * that is not listed for a trigger cannot be saved into a condition, so a rule
 * can never depend on data the event does not actually carry.
 */
final class AutomationDefinition
{
    /**
     * @return list<array{key: string, label: string, fields: list<array{key: string, label: string, type: string, options?: list<array{value: string, label: string}>}>}>
     */
    public static function triggers(): array
    {
        return array_map(static fn (AutomationTrigger $trigger): array => [
            'key' => $trigger->value,
            'label' => $trigger->label(),
            'fields' => self::fieldsFor($trigger),
        ], AutomationTrigger::cases());
    }

    /**
     * @return list<array{value: string, label: string, multiple: bool, needs_value: bool}>
     */
    public static function operators(): array
    {
        return array_map(static fn (AutomationConditionOperator $operator): array => [
            'value' => $operator->value,
            'label' => $operator->label(),
            'multiple' => $operator->acceptsMultipleValues(),
            'needs_value' => $operator->requiresValue(),
        ], AutomationConditionOperator::cases());
    }

    /**
     * @return list<array{key: string, label: string, description: string, config_fields: list<array{key: string, label: string, type: string, required: bool}>}>
     */
    public static function actions(): array
    {
        return [
            [
                'key' => AutomationActionType::NotifyTeam->value,
                'label' => AutomationActionType::NotifyTeam->label(),
                'description' => 'Add an in-app notification for every teammate who can manage automations.',
                'config_fields' => [
                    ['key' => 'message', 'label' => 'Message', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => AutomationActionType::CreateTask->value,
                'label' => AutomationActionType::CreateTask->label(),
                'description' => 'Create a reviewable task inside this workspace.',
                'config_fields' => [
                    ['key' => 'title', 'label' => 'Task title', 'type' => 'text', 'required' => true],
                    ['key' => 'description', 'label' => 'Details', 'type' => 'textarea', 'required' => false],
                    ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => false],
                    ['key' => 'assigned_to', 'label' => 'Assignee ID', 'type' => 'number', 'required' => false],
                    ['key' => 'due_in_days', 'label' => 'Due in days', 'type' => 'number', 'required' => false],
                ],
            ],
            [
                'key' => AutomationActionType::ProposeAiAction->value,
                'label' => AutomationActionType::ProposeAiAction->label(),
                'description' => 'Create a proposal for the existing approval queue. It never executes automatically.',
                'config_fields' => [
                    ['key' => 'action_type', 'label' => 'Action type', 'type' => 'text', 'required' => true],
                    ['key' => 'summary', 'label' => 'Summary', 'type' => 'text', 'required' => false],
                    ['key' => 'payload', 'label' => 'Payload (JSON)', 'type' => 'json', 'required' => true],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function triggerKeys(): array
    {
        return array_map(static fn (AutomationTrigger $trigger): string => $trigger->value, AutomationTrigger::cases());
    }

    /**
     * @return list<string>
     */
    public static function actionKeys(): array
    {
        return array_map(static fn (AutomationActionType $action): string => $action->value, AutomationActionType::cases());
    }

    /**
     * @return list<string>
     */
    public static function operatorKeys(): array
    {
        return array_map(static fn (AutomationConditionOperator $operator): string => $operator->value, AutomationConditionOperator::cases());
    }

    /**
     * @return list<string>
     */
    public static function fieldKeysFor(AutomationTrigger $trigger): array
    {
        return array_column(self::fieldsFor($trigger), 'key');
    }

    /**
     * @return list<string>
     */
    public static function taskPriorities(): array
    {
        return ['low', 'normal', 'high', 'urgent'];
    }

    /**
     * @return list<array{key: string, label: string, type: string, options?: list<array{value: string, label: string}>}>
     */
    private static function fieldsFor(AutomationTrigger $trigger): array
    {
        return match ($trigger) {
            AutomationTrigger::OrderCreated => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('status', 'Status', 'string', self::orderStatusOptions()),
                self::field('payment_status', 'Payment status', 'string'),
                self::field('grand_total', 'Order total', 'number'),
                self::field('subtotal', 'Subtotal', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('item_count', 'Item count', 'number'),
            ],
            AutomationTrigger::OrderStatusChanged => [
                self::field('order_id', 'Order ID', 'number'),
                self::field('order_number', 'Order number', 'string'),
                self::field('from_status', 'Previous status', 'string', self::orderStatusOptions()),
                self::field('to_status', 'New status', 'string', self::orderStatusOptions()),
                self::field('grand_total', 'Order total', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('note', 'Status note', 'string'),
            ],
            AutomationTrigger::InventoryLowStock => [
                self::field('inventory_id', 'Inventory ID', 'number'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('store_name', 'Store name', 'string'),
                self::field('product_id', 'Product ID', 'number'),
                self::field('product_variant_id', 'Variant ID', 'number'),
                self::field('product_name', 'Product name', 'string'),
                self::field('sku', 'SKU', 'string'),
                self::field('quantity', 'On hand', 'number'),
                self::field('reserved_quantity', 'Reserved', 'number'),
                self::field('available', 'Available', 'number'),
                self::field('threshold', 'Low-stock threshold', 'number'),
            ],
            AutomationTrigger::ConversationMessageReceived => [
                self::field('conversation_id', 'Conversation ID', 'number'),
                self::field('message_id', 'Message ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('customer_name', 'Customer name', 'string'),
                self::field('channel', 'Channel', 'string'),
                self::field('status', 'Conversation status', 'string'),
                self::field('assigned_to', 'Assigned user ID', 'number'),
                self::field('preview', 'Message preview', 'string'),
                self::field('store_id', 'Store ID', 'number'),
            ],
            AutomationTrigger::RecoveryOpportunityDetected => [
                self::field('opportunity_id', 'Opportunity ID', 'number'),
                self::field('reason', 'Reason', 'string', self::recoveryReasonOptions()),
                self::field('status', 'Status', 'string', self::recoveryStatusOptions()),
                self::field('estimated_value', 'Estimated value', 'number'),
                self::field('currency', 'Currency', 'string'),
                self::field('store_id', 'Store ID', 'number'),
                self::field('customer_id', 'Customer ID', 'number'),
                self::field('conversation_id', 'Conversation ID', 'number'),
                self::field('cart_id', 'Cart ID', 'number'),
                self::field('assigned_to', 'Assigned user ID', 'number'),
            ],
        };
    }

    /**
     * @param  list<array{value: string, label: string}>|null  $options
     * @return array{key: string, label: string, type: string, options?: list<array{value: string, label: string}>}
     */
    private static function field(string $key, string $label, string $type, ?array $options = null): array
    {
        $field = ['key' => $key, 'label' => $label, 'type' => $type];

        if ($options !== null) {
            $field['options'] = $options;
        }

        return $field;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function orderStatusOptions(): array
    {
        return array_map(static fn (OrderStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], OrderStatus::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function recoveryReasonOptions(): array
    {
        return array_map(static fn (RecoveryReason $reason): array => [
            'value' => $reason->value,
            'label' => $reason->label(),
        ], RecoveryReason::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function recoveryStatusOptions(): array
    {
        return array_map(static fn (RecoveryOpportunityStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], RecoveryOpportunityStatus::cases());
    }
}
