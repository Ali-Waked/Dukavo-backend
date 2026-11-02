<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => Store::factory(),
            'name' => fake()->unique()->city().' delivery',
            'city' => fake()->city(),
            'areas' => null,
            'fee' => 10,
            'free_shipping_threshold' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->getKey(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
