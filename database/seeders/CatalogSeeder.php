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
 *
 * Idempotent by tenant slug, product slug and variant SKU. A second run updates
 * the demonstration rows in place instead of duplicating them, and leaves any
 * category or product a merchant created with a different slug untouched.
 * Categories and stores carry Arabic copy alongside the canonical English, so
 * the demo looks bilingual without a second seed pass.
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

            $price = min(array_column($spec['variants'], 'price'));
            $cost = min(array_column($spec['variants'], 'cost'));

            $product = Product::query()->firstOrNew(['slug' => $spec['key']]);
            $isNewProduct = ! $product->exists;

            $product->fill([
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
            ]);

            // Only a new row gets a demonstration creation date. An existing row
            // keeps the timestamp it already has, so re-seeding does not shuffle
            // the "newest products" ordering or rewrite history.
            if ($isNewProduct) {
                $productDate = now()->subDays(40 + (($index * 7) % 46));
                $product->forceFill(['created_at' => $productDate, 'updated_at' => $productDate]);
            }

            $product->save();
            $productDate = $product->created_at ?? now();

            foreach ($spec['variants'] as $position => $variant) {
                $record = ProductVariant::query()->firstOrNew(['sku' => $variant['sku']]);
                $isNewVariant = ! $record->exists;

                $record->fill([
                    'tenant_id' => $tenant->getKey(),
                    'product_id' => $product->getKey(),
                    'name' => $variant['name'],
                    'sku' => $variant['sku'],
                    'price' => $variant['price'],
                    'cost' => $variant['cost'],
                    'is_default' => $position === 0,
                    'position' => $position + 1,
                ]);

                if ($isNewVariant) {
                    $record->forceFill(['created_at' => $productDate, 'updated_at' => $productDate]);
                }

                $record->save();
            }

            if (is_file($this->imageDirectory().'/'.$key.'.jpg')) {
                $image = ProductImage::query()->firstOrNew([
                    'product_id' => $product->getKey(),
                    'path' => 'products/'.$key.'.jpg',
                ]);
                $isNewImage = ! $image->exists;

                $image->fill([
                    'tenant_id' => $tenant->getKey(),
                    'product_variant_id' => null,
                    'disk' => 'public',
                    'alt_text' => $spec['name'],
                    'is_primary' => true,
                    'position' => 0,
                ]);

                if ($isNewImage) {
                    $image->forceFill(['created_at' => $productDate, 'updated_at' => $productDate]);
                }

                $image->save();
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
            $parent = Category::query()->firstOrNew(['slug' => $root['slug']]);
            $isNewParent = ! $parent->exists;

            $parent->fill([
                'tenant_id' => $tenant->getKey(),
                'parent_id' => null,
                'name' => $root['name'],
                'name_ar' => $root['name_ar'],
                'description' => $root['description'],
                'description_ar' => $root['description_ar'],
                'is_active' => true,
                'sort_order' => ($rootIndex + 1) * 10,
            ]);

            // Only a new row is backdated. Re-seeding must not rewrite the
            // creation date of a category a merchant may already have seen.
            if ($isNewParent) {
                $parent->forceFill(['created_at' => $treeDate, 'updated_at' => $treeDate]);
            }

            $parent->save();

            $ids[$root['slug']] = $parent->getKey();

            foreach ($root['children'] as $childIndex => $child) {
                $category = Category::query()->firstOrNew(['slug' => $child['slug']]);
                $isNewChild = ! $category->exists;

                $category->fill([
                    'tenant_id' => $tenant->getKey(),
                    'parent_id' => $parent->getKey(),
                    'name' => $child['name'],
                    'name_ar' => $child['name_ar'],
                    'description' => $child['description'],
                    'description_ar' => $child['description_ar'],
                    'is_active' => true,
                    'sort_order' => ($childIndex + 1) * 10,
                ]);

                if ($isNewChild) {
                    $category->forceFill(['created_at' => $treeDate, 'updated_at' => $treeDate]);
                }

                $category->save();

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
