<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Customer records and their saved addresses.
 *
 * Addresses are simple CRUD with one rule: a customer has at most one default
 * address per type, enforced by clearing the others inside the transaction.
 */
class CustomerService
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Customer
    {
        return DB::transaction(function () use ($attributes): Customer {
            $tenant = $this->tenants->required();

            $customer = Customer::query()->create([
                'tenant_id' => $tenant->getKey(),
                'store_id' => $attributes['store_id'] ?? null,
                'name' => $attributes['name'],
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'status' => $attributes['status'] ?? 'active',
                'locale' => $attributes['locale'] ?? $tenant->locale,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $this->syncAddresses($customer, $attributes['addresses'] ?? []);

            return $customer->load('addresses');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes): Customer {
            if (array_key_exists('addresses', $attributes)) {
                $this->syncAddresses($customer, $attributes['addresses']);
                unset($attributes['addresses']);
            }

            $customer->fill($attributes)->save();

            return $customer->load('addresses');
        });
    }

    /**
     * A customer with orders is archived rather than deleted: order history and
     * the audit trail must keep referring to a real record.
     */
    public function delete(Customer $customer): void
    {
        if (Order::query()->where('customer_id', $customer->getKey())->exists()) {
            $customer->delete();

            return;
        }

        DB::transaction(function () use ($customer): void {
            $customer->addresses()->delete();
            $customer->forceDelete();
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     */
    private function syncAddresses(Customer $customer, array $addresses): void
    {
        $keptIds = [];

        foreach ($addresses as $address) {
            $payload = [
                'type' => $address['type'] ?? 'shipping',
                'contact_name' => $address['contact_name'],
                'line_one' => $address['line_one'],
                'line_two' => $address['line_two'] ?? null,
                'city' => $address['city'],
                'state' => $address['state'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country' => strtoupper((string) $address['country']),
                'is_default' => (bool) ($address['is_default'] ?? false),
            ];

            if (isset($address['id'])) {
                $existing = $customer->addresses()->find($address['id']);

                if ($existing !== null) {
                    $existing->update($payload);
                    $keptIds[] = $existing->getKey();

                    if ($existing->is_default) {
                        $this->clearOtherDefaults($customer, $existing);
                    }

                    continue;
                }
            }

            $created = $customer->addresses()->create($payload);
            $keptIds[] = $created->getKey();

            if ($created->is_default) {
                $this->clearOtherDefaults($customer, $created);
            }
        }

        $customer->addresses()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    private function clearOtherDefaults(Customer $customer, CustomerAddress $address): void
    {
        $customer->addresses()
            ->whereKeyNot($address->getKey())
            ->where('type', $address->type)
            ->update(['is_default' => false]);
    }
}
