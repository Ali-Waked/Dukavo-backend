<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Store;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Customers\CustomerService;

/**
 * Creates a customer record from a reviewed proposal.
 *
 * The effect is a draft-quality record: a person approved it, and the customer
 * can still be edited or archived like any other. No financial or messaging
 * side effects occur here.
 */
final class CustomerCreateExecutor implements AiActionExecutor
{
    public function __construct(private readonly CustomerService $customers) {}

    public function actionType(): string
    {
        return 'customer.create';
    }

    public function classification(): AiActionClassification
    {
        return AiActionClassification::DraftCreation;
    }

    public function requiredPermission(): string
    {
        return 'customers.create';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload): array
    {
        $name = $this->stringOrNull($payload['name'] ?? null, 180);

        if ($name === null) {
            throw BusinessRuleViolation::because('The proposed customer needs a name.');
        }

        $storeId = $payload['store_id'] ?? null;
        $store = null;

        if ($storeId !== null && $storeId !== '' && (int) $storeId > 0) {
            $store = Store::query()->find((int) $storeId);

            if (! $store instanceof Store) {
                throw BusinessRuleViolation::because('The proposed store does not exist in this workspace.');
            }
        }

        $email = $this->stringOrNull($payload['email'] ?? null, 180);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw BusinessRuleViolation::because('The proposed customer email is not valid.');
        }

        return [
            'store_id' => $store?->getKey(),
            'store_name' => $store?->name,
            'name' => $name,
            'email' => $email,
            'phone' => $this->stringOrNull($payload['phone'] ?? null, 32),
            'notes' => $this->stringOrNull($payload['notes'] ?? null, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function revalidate(array $payload): array
    {
        return $this->resolve($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload, User $actor, string $idempotencyKey): array
    {
        $customer = $this->customers->create([
            'store_id' => $payload['store_id'] ?? null,
            'name' => $payload['name'],
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        return [
            'customer_id' => $customer->getKey(),
            'name' => $customer->name,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
