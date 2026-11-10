<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Store;
use App\Services\Payments\Data\PaymentOption;
use App\Services\Payments\PaymentEligibilityService;

/**
 * Payment method availability.
 *
 * Cash on delivery and bank transfer are merchant-configured, non-provider
 * methods. Online payment is offered only when {@see PaymentEligibilityService}
 * can resolve a verified, active connection and a real local rail; otherwise it
 * is reported unavailable with the reason, and checkout refuses it rather than
 * pretending a card was charged.
 */
class PaymentService
{
    public function __construct(private readonly PaymentEligibilityService $eligibility) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(Store $store): array
    {
        $stored = $store->payment_settings ?? [];

        return [
            'cod_enabled' => (bool) ($stored['cod_enabled'] ?? true),
            'cod_instructions' => $stored['cod_instructions'] ?? null,
            'bank_transfer_enabled' => (bool) ($stored['bank_transfer_enabled'] ?? false),
            'bank_transfer_instructions' => $stored['bank_transfer_instructions'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function methods(Store $store): array
    {
        $settings = $this->settings($store);
        [$options, $reasons] = $this->eligibility->evaluate($store);

        $onlineMethods = array_map(
            static fn ($option): array => $option->toPublicArray(),
            $options,
        );

        return [
            [
                'code' => PaymentMethod::Cod->value,
                'label' => PaymentMethod::Cod->label(),
                'description' => PaymentMethod::Cod->description(),
                'available' => $settings['cod_enabled'],
                'unavailable_reason' => $settings['cod_enabled']
                    ? null
                    : 'This store does not accept cash on delivery.',
                'instructions' => $settings['cod_instructions'],
                'methods' => [],
            ],
            [
                'code' => PaymentMethod::BankTransfer->value,
                'label' => PaymentMethod::BankTransfer->label(),
                'description' => PaymentMethod::BankTransfer->description(),
                'available' => $settings['bank_transfer_enabled'],
                'unavailable_reason' => $settings['bank_transfer_enabled']
                    ? null
                    : 'This store does not accept bank transfer.',
                'instructions' => $settings['bank_transfer_instructions'],
                'methods' => [],
            ],
            [
                'code' => PaymentMethod::Online->value,
                'label' => PaymentMethod::Online->label(),
                'description' => PaymentMethod::Online->description(),
                'available' => $onlineMethods !== [],
                'unavailable_reason' => $onlineMethods === []
                    ? ($reasons[0] ?? 'No online payment provider is configured for this store yet.')
                    : null,
                'instructions' => null,
                'methods' => $onlineMethods,
            ],
        ];
    }

    /**
     * Resolve the exact online rail a checkout asked for. The returned option
     * contains server-only provider configuration and must never be serialised.
     */
    public function onlineOption(Store $store, ?string $methodCode = null): PaymentOption
    {
        return $this->eligibility->assertAvailable($store, $methodCode);
    }

    public function assertAvailable(Store $store, PaymentMethod $method, ?string $methodCode = null): void
    {
        $settings = $this->settings($store);

        if ($method === PaymentMethod::Online) {
            $this->eligibility->assertAvailable($store, $methodCode);

            return;
        }

        if ($method === PaymentMethod::BankTransfer) {
            if (! $settings['bank_transfer_enabled']) {
                throw BusinessRuleViolation::because('This store does not accept bank transfer.');
            }

            return;
        }

        if (! $settings['cod_enabled']) {
            throw BusinessRuleViolation::because('This store does not accept cash on delivery.');
        }
    }
}
