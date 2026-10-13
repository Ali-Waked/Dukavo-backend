<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * The people who buy from each merchant, in the merchant's own language.
 *
 * Customers are written before {@see OrderSeeder} runs so every order can be
 * attributed to an existing account, and every one of them is backdated past
 * the oldest order — a customer cannot have ordered before they existed.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $offset => $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run($tenant, function () use ($tenant, $definition, $offset): void {
                $this->seedCustomers($tenant, $definition, $offset);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedCustomers(Tenant $tenant, array $definition, int $offset): void
    {
        $locale = (string) $definition['locale'];
        $notes = $this->notes($locale);

        foreach (range(0, (int) $definition['customer_count'] - 1) as $index) {
            $person = DemoPeople::customer($locale, $offset, $index);
            $joinedAt = now()->subDays(rand(75, 115));

            $customer = Customer::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'store_id' => null,
                'name' => $person['name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
                'status' => 'active',
                'locale' => $locale,
                'notes' => rand(0, 99) < 18 ? $notes[rand(0, count($notes) - 1)] : null,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);

            $address = DemoPeople::address($locale, $index, $person['name']);

            CustomerAddress::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'customer_id' => $customer->getKey(),
                'type' => 'shipping',
                'contact_name' => $address['contact_name'],
                'line_one' => $address['line_one'],
                'line_two' => $address['line_two'],
                'city' => $address['city'],
                'state' => $address['state'],
                'postal_code' => $address['postal_code'],
                'country' => $address['country'],
                'is_default' => true,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function notes(string $locale): array
    {
        if ($locale === 'ar') {
            return [
                'يفضل التوصيل بعد الرابعة عصراً.',
                'زبون دائم منذ افتتاح الفرع.',
                'الدفع عند الاستلام فقط.',
            ];
        }

        return [
            'Prefers delivery after 4pm.',
            'Long-standing regular.',
            'Cash on delivery only.',
        ];
    }
}
