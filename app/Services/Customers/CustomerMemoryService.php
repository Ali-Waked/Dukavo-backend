<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerMemory;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerMemoryService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Customer $customer, array $attributes, ?User $actor = null): CustomerMemory
    {
        return DB::transaction(function () use ($customer, $attributes, $actor): CustomerMemory {
            $tenant = $this->tenants->required();

            $attributes = [
                'tenant_id' => $tenant->getKey(),
                'customer_id' => $customer->getKey(),
                'created_by' => $actor?->getKey(),
                'updated_by' => $actor?->getKey(),
                'key' => $attributes['key'],
                'value' => $attributes['value'],
                'category' => $attributes['category'] ?? 'general',
                'source' => $attributes['source'] ?? 'manual',
                'confidence' => $attributes['confidence'] ?? null,
                'meta' => $attributes['meta'] ?? null,
                'expires_at' => $attributes['expires_at'] ?? null,
                'review_at' => $attributes['review_at'] ?? null,
            ];

            // A soft-deleted memory still occupies the unique key, so a fact
            // the merchant deleted and later re-enters restores that row
            // instead of failing the insert.
            $existing = CustomerMemory::withTrashed()
                ->where('tenant_id', $tenant->getKey())
                ->where('customer_id', $customer->getKey())
                ->where('key', $attributes['key'])
                ->first();

            if ($existing instanceof CustomerMemory) {
                if (! $existing->trashed()) {
                    throw ValidationException::withMessages([
                        'key' => __('A memory with this key already exists for the customer.'),
                    ]);
                }

                $existing->restore();
                $existing->fill(Arr::except($attributes, ['created_by']))->save();

                return $existing->fresh();
            }

            return CustomerMemory::query()->create($attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(CustomerMemory $memory, array $attributes, ?User $actor = null): CustomerMemory
    {
        if ($actor) {
            $attributes['updated_by'] = $actor->getKey();
        }

        $memory->fill($attributes)->save();

        return $memory->fresh();
    }

    public function delete(CustomerMemory $memory): void
    {
        $memory->delete();
    }
}
