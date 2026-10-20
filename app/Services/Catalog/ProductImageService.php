<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Product gallery management.
 *
 * A product owns an ordered gallery with exactly one primary image (when it has
 * any). Both rules are enforced here rather than in the controller because a
 * second caller — an importer, a job — could otherwise leave a product with two
 * primaries or a deleted file still referenced by the database.
 *
 * Files are written to the public disk and the row stores the relative path;
 * deletion removes the file and promotes the next image, so the gallery never
 * points at a missing file or loses its primary.
 */
class ProductImageService
{
    private const IMAGE_DISK = 'public';

    /**
     * Store one or more uploaded files against the product.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string|null>  $altTexts  Optional alt text, keyed by the same index as $files
     * @return Collection<int, ProductImage>
     */
    public function store(Product $product, array $files, array $altTexts = []): Collection
    {
        return DB::transaction(function () use ($product, $files, $altTexts): Collection {
            $position = (int) $product->images()->max('position');
            $hasPrimary = $product->images()->where('is_primary', true)->exists();
            $created = new Collection;

            foreach (array_values($files) as $index => $file) {
                $position++;

                $path = $file->store('products/'.$product->getKey(), self::IMAGE_DISK);

                $image = $product->images()->create([
                    'disk' => self::IMAGE_DISK,
                    'path' => $path,
                    'alt_text' => $altTexts[$index] ?? null,
                    // The first image of an otherwise-empty gallery becomes the
                    // primary one; later uploads never steal that flag.
                    'is_primary' => ! $hasPrimary && $index === 0,
                    'position' => $position,
                ]);

                if ($image->is_primary) {
                    $hasPrimary = true;
                }

                $created->push($image);
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProductImage $image, array $attributes): ProductImage
    {
        return DB::transaction(function () use ($image, $attributes): ProductImage {
            if (($attributes['is_primary'] ?? false) === true) {
                // Promoting one image demotes the rest: the invariant is a
                // single primary per product, not "at least one".
                ProductImage::query()
                    ->where('product_id', $image->product_id)
                    ->whereKeyNot($image->getKey())
                    ->update(['is_primary' => false]);
            }

            $image->fill($attributes)->save();

            return $image;
        });
    }

    /**
     * Remove an image, its file, and promote the next one when the removed image
     * was primary.
     */
    public function delete(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $productId = $image->product_id;
            $wasPrimary = $image->is_primary;

            Storage::disk($image->disk)->delete($image->path);
            $image->delete();

            if ($wasPrimary) {
                ProductImage::query()
                    ->where('product_id', $productId)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->first()
                    ?->forceFill(['is_primary' => true])
                    ->save();
            }
        });
    }

    /**
     * Rewrite gallery order from a list of image ids. Ids not belonging to the
     * product are ignored; images missing from the list keep their relative
     * order after the listed ones.
     *
     * @param  array<int, int>  $ids
     */
    public function reorder(Product $product, array $ids): void
    {
        DB::transaction(function () use ($product, $ids): void {
            $images = $product->images()->get()->keyBy('id');
            $position = 0;

            foreach ($ids as $id) {
                $image = $images->get((int) $id);

                if ($image === null) {
                    continue;
                }

                $position++;
                $image->forceFill(['position' => $position])->save();
                $images->forget((int) $id);
            }

            foreach ($images as $image) {
                $position++;
                $image->forceFill(['position' => $position])->save();
            }
        });
    }
}
