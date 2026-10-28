<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'from_store_id' => Store::factory(),
            'to_store_id' => Store::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity' => fake()->numberBetween(1, 50),
            'note' => fake()->sentence(),
            'user_id' => null,
            'idempotency_key' => null,
            'from_movement_id' => null,
            'to_movement_id' => null,
        ];
    }

    public function forVariant(Store $from, Store $to, ProductVariant $variant, int $quantity = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $variant->tenant_id,
            'from_store_id' => $from->getKey(),
            'to_store_id' => $to->getKey(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'quantity' => $quantity,
        ]);
    }
}
