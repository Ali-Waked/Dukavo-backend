<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationActionType;
use App\Enums\NotificationChannel;
use App\Services\Notifications\ChannelAvailability;
use Throwable;

/**
 * The single catalogue of supported automation actions.
 *
 * An action that depends on an integration is only advertised as available when
 * that integration can actually deliver. The runner enforces the same check at
 * execution time, so a rule saved before a channel was disabled is recorded as
 * skipped with a reason instead of pretending a message went out.
 */
final class AutomationActionRegistry
{
    /**
     * @return list<array{key: string, label: string, description: string, category: string, risk: string, available: bool, availability_reason: string|null, config_fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return array_map(
            static fn (AutomationActionType $action): array => self::describe($action),
            AutomationActionType::cases(),
        );
    }

    /**
     * @return array{key: string, label: string, description: string, category: string, risk: string, available: bool, availability_reason: string|null, config_fields: list<array<string, mixed>>}|null
     */
    public static function find(string $key): ?array
    {
        $action = AutomationActionType::tryFrom($key);

        return $action instanceof AutomationActionType ? self::describe($action) : null;
    }

    /**
     * @return array{key: string, label: string, description: string, category: string, risk: string, available: bool, availability_reason: string|null, config_fields: list<array<string, mixed>>}
     */
    public static function describe(AutomationActionType $action): array
    {
        $reason = self::availabilityReason($action);

        return [
            'key' => $action->value,
            'label' => $action->label(),
            'description' => self::descriptionFor($action),
            'category' => $action->category(),
            'risk' => $action->risk(),
            'available' => $reason === null,
            'availability_reason' => $reason,
            'config_fields' => self::configFieldsFor($action),
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (AutomationActionType $action): string => $action->value, AutomationActionType::cases());
    }

    /**
     * @return list<string>
     */
    public static function availableKeys(): array
    {
        return array_values(array_map(
            static fn (AutomationActionType $action): string => $action->value,
            array_filter(AutomationActionType::cases(), static fn (AutomationActionType $action): bool => self::isAvailable($action)),
        ));
    }

    public static function isAvailable(AutomationActionType $action): bool
    {
        return self::availabilityReason($action) === null;
    }

    public static function availabilityReason(AutomationActionType $action): ?string
    {
        return match ($action) {
            AutomationActionType::SendCustomerEmail => self::mailReason(),
            AutomationActionType::SendCustomerWhatsApp => self::whatsAppReason(),
            default => null,
        };
    }

    private static function mailReason(): ?string
    {
        return config('dukavo.notifications.channels.mail.enabled', true)
            ? null
            : 'mail_disabled';
    }

    private static function whatsAppReason(): ?string
    {
        try {
            return app(ChannelAvailability::class)->reason(NotificationChannel::WhatsApp);
        } catch (Throwable) {
            return 'whatsapp_transport_unavailable';
        }
    }

    private static function descriptionFor(AutomationActionType $action): string
    {
        return match ($action) {
            AutomationActionType::NotifyTeam => 'Add an in-app notification for every teammate who can manage automations.',
            AutomationActionType::CreateTask => 'Create a reviewable task inside this workspace.',
            AutomationActionType::ProposeAiAction => 'Create a proposal for the existing approval queue. It never executes automatically.',
            AutomationActionType::SendCustomerEmail => 'Queue a transactional email to the customer on this event. Requires a customer email address.',
            AutomationActionType::SendCustomerWhatsApp => 'Send a business-initiated WhatsApp message. Requires an approved template and a destination-bound transport.',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function configFieldsFor(AutomationActionType $action): array
    {
        return match ($action) {
            AutomationActionType::NotifyTeam => [
                ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
            AutomationActionType::CreateTask => [
                ['key' => 'title', 'label' => 'Task title', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Details', 'type' => 'textarea', 'required' => false],
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => false, 'options' => [
                    ['value' => 'low', 'label' => 'Low'],
                    ['value' => 'normal', 'label' => 'Normal'],
                    ['value' => 'high', 'label' => 'High'],
                    ['value' => 'urgent', 'label' => 'Urgent'],
                ]],
                ['key' => 'assigned_to', 'label' => 'Assignee user ID', 'type' => 'number', 'required' => false],
                ['key' => 'due_in_days', 'label' => 'Due in days', 'type' => 'number', 'required' => false],
            ],
            AutomationActionType::ProposeAiAction => [
                ['key' => 'action_type', 'label' => 'Action type', 'type' => 'text', 'required' => true],
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'text', 'required' => false],
                ['key' => 'payload', 'label' => 'Payload (JSON)', 'type' => 'json', 'required' => true],
            ],
            AutomationActionType::SendCustomerEmail => [
                ['key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true],
                ['key' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
            AutomationActionType::SendCustomerWhatsApp => [
                ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ],
        };
    }
}
