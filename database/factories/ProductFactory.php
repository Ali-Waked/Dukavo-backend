<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'tenant_id' => Tenant::factory(),
            'store_id' => null,
            'category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'sku' => strtoupper(Str::random(10)),
            'description' => fake()->sentence(),
            'status' => ProductStatus::Active,
            'price' => fake()->randomFloat(2, 5, 500),
            'cost' => fake()->randomFloat(2, 1, 250),
            'currency' => 'USD',
            'is_taxable' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ProductStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ProductStatus::Archived]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (array $attributes): array => ['category_id' => $category->getKey()]);
    }

    public function atStore(Store $store): static
    {
        return $this->state(fn (array $attributes): array => ['store_id' => $store->getKey()]);
    }
}
