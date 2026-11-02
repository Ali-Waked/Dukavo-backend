<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Store;

/**
 * Payment method availability.
 *
 * Cash on delivery is genuinely available: the order is recorded as unpaid and
 * the merchant collects on delivery. Online payment is deliberately reported as
 * unavailable because no provider is configured, so checkout cannot pretend a
 * card was charged.
 */
class PaymentService
{
    /**
     * @return array<string, mixed>
     */
    public function settings(Store $store): array
    {
        $stored = $store->payment_settings ?? [];

        return [
            'cod_enabled' => (bool) ($stored['cod_enabled'] ?? true),
            'cod_instructions' => $stored['cod_instructions'] ?? null,
        ];
    }

    /**
     * @return list<array{code: string, label: string, description: string, available: bool, unavailable_reason: string|null}>
     */
    public function methods(Store $store): array
    {
        $settings = $this->settings($store);

        return [
            [
                'code' => PaymentMethod::Cod->value,
                'label' => PaymentMethod::Cod->label(),
                'description' => PaymentMethod::Cod->description(),
                'available' => $settings['cod_enabled'],
                'unavailable_reason' => $settings['cod_enabled']
                    ? null
                    : 'This store does not accept cash on delivery.',
            ],
            [
                'code' => PaymentMethod::Online->value,
                'label' => PaymentMethod::Online->label(),
                'description' => PaymentMethod::Online->description(),
                'available' => false,
                'unavailable_reason' => 'No online payment provider is configured for this store yet.',
            ],
        ];
    }

    public function assertAvailable(Store $store, PaymentMethod $method): void
    {
        if ($method === PaymentMethod::Online) {
            throw BusinessRuleViolation::because(
                'Online payment is not available yet. Please choose cash on delivery.'
            );
        }

        if (! $this->settings($store)['cod_enabled']) {
            throw BusinessRuleViolation::because('This store does not accept cash on delivery.');
        }
    }
}
