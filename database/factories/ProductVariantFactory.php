<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The variant inherits the tenant of its product; a factory cannot
            // read the other model's attribute, so tests that need one pass
            // `forProduct()` explicitly.
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'name' => 'Default',
            'sku' => strtoupper(Str::random(10)),
            'barcode' => fake()->ean13(),
            'price' => fake()->randomFloat(2, 5, 500),
            'cost' => fake()->randomFloat(2, 1, 250),
            'is_default' => true,
            'position' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->getKey(),
            'price' => $product->price,
            'cost' => $product->cost,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => ['is_default' => true]);
    }
}
