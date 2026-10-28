<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BundleItem>
 */
class BundleItemFactory extends Factory
{
    protected $model = BundleItem::class;

    /**
     * The variant is created from the product that was just resolved, so a
     * default item can never pair a product with a variant that belongs to a
     * different product. Explicit product/variant attributes still win.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'bundle_id' => Bundle::factory(),
            'product_id' => function (array $attributes): int {
                $bundle = Bundle::withoutGlobalScopes()->find($attributes['bundle_id']);

                return Product::factory()->create([
                    'tenant_id' => $bundle?->tenant_id ?? $attributes['tenant_id'],
                    'store_id' => $bundle?->store_id,
                    'currency' => $bundle?->currency ?? 'USD',
                ])->getKey();
            },
            'product_variant_id' => function (array $attributes): int {
                $product = Product::withoutGlobalScopes()->findOrFail($attributes['product_id']);

                return ProductVariant::factory()->forProduct($product)->create()->getKey();
            },
            'quantity' => 1,
            'position' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BundleItem $item): void {
            $product = Product::withoutGlobalScopes()->find($item->product_id);

            if ($product instanceof Product) {
                $item->tenant_id = $product->tenant_id;
            }
        });
    }
}
