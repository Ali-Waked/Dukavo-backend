<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoCatalog;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Categories, products, variants and imagery — the part of the demo a merchant
 * looks at all day.
 *
 * Every workspace gets its own copy of the (different) slice of the catalogue
 * it carries, so switching tenant switches the assortment rather than the
 * labels. Product rows are written directly rather than through
 * ProductService because a demo needs products that were "created" over the
 * last few weeks, not all of them today.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->publishImages();
        $context = app(TenantContext::class);

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->firstOrFail();

            $context->run(
                $tenant,
                fn (): int => $this->seedCatalog($tenant, $definition),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedCatalog(Tenant $tenant, array $definition): int
    {
        $categoryIds = $this->categories($tenant);
        $keys = DemoCatalog::productKeys((int) $definition['tiers'], $definition['omit']);
        $missingImages = [];
        $seeded = 0;

        foreach ($keys as $index => $key) {
            $spec = DemoCatalog::product($key);

            if ($spec === null) {
                continue;
            }

            $productDate = now()->subDays(rand(40, 85));
            $price = min(array_column($spec['variants'], 'price'));
            $cost = min(array_column($spec['variants'], 'cost'));

            $product = Product::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'store_id' => null,
                'category_id' => DemoCatalog::categoryId($categoryIds, $spec['category']),
                'name' => $spec['name'],
                'slug' => $spec['key'],
                'sku' => $spec['sku'],
                'description' => $spec['description'],
                'status' => $this->statusFor($index),
                'price' => $price,
                'cost' => $cost,
                'currency' => 'USD',
                'is_taxable' => $spec['taxable'],
                'created_at' => $productDate,
                'updated_at' => $productDate,
            ]);

            foreach ($spec['variants'] as $position => $variant) {
                ProductVariant::forceCreate([
                    'tenant_id' => $tenant->getKey(),
                    'product_id' => $product->getKey(),
                    'name' => $variant['name'],
                    'sku' => $variant['sku'],
                    'price' => $variant['price'],
                    'cost' => $variant['cost'],
                    'is_default' => $position === 0,
                    'position' => $position + 1,
                    'created_at' => $productDate,
                    'updated_at' => $productDate,
                ]);
            }

            if (is_file($this->imageDirectory().'/'.$key.'.jpg')) {
                ProductImage::forceCreate([
                    'tenant_id' => $tenant->getKey(),
                    'product_id' => $product->getKey(),
                    'product_variant_id' => null,
                    'disk' => 'public',
                    'path' => 'products/'.$key.'.jpg',
                    'alt_text' => $spec['name'],
                    'is_primary' => true,
                    'position' => 0,
                    'created_at' => $productDate,
                    'updated_at' => $productDate,
                ]);
            } else {
                $missingImages[] = $key;
            }

            $seeded++;
        }

        if ($missingImages !== []) {
            $this->command?->warn(sprintf(
                'Products without imagery in [%s]: %s.',
                $definition['name'],
                implode(', ', array_unique($missingImages)),
            ));
        }

        return $seeded;
    }

    /**
     * The category tree, roots first, with navigation sort order.
     *
     * @return array<string, int> keyed by category slug
     */
    private function categories(Tenant $tenant): array
    {
        $ids = [];
        $treeDate = $tenant->created_at ?? now()->subDays(110);

        foreach (DemoCatalog::categories() as $rootIndex => $root) {
            $parent = Category::forceCreate([
                'tenant_id' => $tenant->getKey(),
                'parent_id' => null,
                'name' => $root['name'],
                'slug' => $root['slug'],
                'description' => $root['description'],
                'is_active' => true,
                'sort_order' => ($rootIndex + 1) * 10,
                'created_at' => $treeDate,
                'updated_at' => $treeDate,
            ]);

            $ids[$root['slug']] = $parent->getKey();

            foreach ($root['children'] as $childIndex => $child) {
                $category = Category::forceCreate([
                    'tenant_id' => $tenant->getKey(),
                    'parent_id' => $parent->getKey(),
                    'name' => $child['name'],
                    'slug' => $child['slug'],
                    'description' => $child['description'],
                    'is_active' => true,
                    'sort_order' => ($childIndex + 1) * 10,
                    'created_at' => $treeDate,
                    'updated_at' => $treeDate,
                ]);

                $ids[$child['slug']] = $category->getKey();
            }
        }

        return $ids;
    }

    /**
     * A shelf that is not fully stocked yet, plus a couple of lines that were
     * discontinued: a catalogue where every product is Active reads like a
     * fixture, not like a shop.
     */
    private function statusFor(int $index): ProductStatus
    {
        return match (true) {
            $index % 17 === 6 => ProductStatus::Draft,
            $index % 23 === 11 => ProductStatus::Archived,
            default => ProductStatus::Active,
        };
    }

    /**
     * Copy the committed demo photographs onto the public disk and link
     * `public/storage`, so `ProductImageResource` can resolve URLs exactly as
     * it would for an uploaded image. A missing image directory is not fatal:
     * the products simply carry no picture row.
     */
    private function publishImages(): void
    {
        $source = $this->imageDirectory();

        if (! is_dir($source)) {
            return;
        }

        $files = glob($source.'/*.jpg');

        if ($files === false || $files === []) {
            return;
        }

        $destination = storage_path('app/public/products');
        File::ensureDirectoryExists($destination);

        foreach ($files as $file) {
            File::copy($file, $destination.DIRECTORY_SEPARATOR.basename($file));
        }

        if (! file_exists(public_path('storage'))) {
            Artisan::call('storage:link');
        }
    }

    private function imageDirectory(): string
    {
        return database_path('seeders/demo-images');
    }
}
