<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $store = Store::factory();
        $variant = ProductVariant::factory();

        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => $store,
            'product_variant_id' => $variant,
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(0, 100),
            'reserved_quantity' => 0,
            'low_stock_threshold' => 5,
        ];
    }

    /**
     * A stocked row for a variant that already exists in a real store, with the
     * tenant taken from the variant so the composite uniqueness holds.
     */
    public function forVariant(Store $store, ProductVariant $variant, int $quantity = 25): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $variant->tenant_id,
            'store_id' => $store->getKey(),
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
            'quantity' => $quantity,
        ]);
    }
}
