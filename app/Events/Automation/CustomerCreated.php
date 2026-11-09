<?php

declare(strict_types=1);

namespace App\Events\Automation;

use App\Enums\AutomationTrigger;
use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class CustomerCreated implements AutomationEvent
{
    use Dispatchable;

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        private int $tenantId,
        private ?int $storeId,
        private array $context,
        private string $eventKey,
    ) {}

    public static function fromCustomer(Customer $customer): self
    {
        return new self(
            tenantId: (int) $customer->tenant_id,
            storeId: $customer->store_id !== null ? (int) $customer->store_id : null,
            context: [
                'customer_id' => (int) $customer->getKey(),
                'customer_name' => (string) $customer->name,
                'customer_email' => (string) ($customer->email ?? ''),
                'customer_phone' => (string) ($customer->phone ?? ''),
                'store_id' => $customer->store_id !== null ? (int) $customer->store_id : null,
                'status' => (string) $customer->status,
                'locale' => (string) $customer->locale,
            ],
            eventKey: 'customer.created:'.$customer->getKey(),
        );
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function storeId(): ?int
    {
        return $this->storeId;
    }

    public function trigger(): AutomationTrigger
    {
        return AutomationTrigger::CustomerCreated;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function dedupeKey(): string
    {
        return $this->eventKey;
    }
}
