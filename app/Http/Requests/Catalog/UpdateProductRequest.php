<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\ProductStatus;
use App\Http\Requests\ApiRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Updating a product.
 *
 * The variant set is replaced wholesale rather than patched per id: a partial
 * patch cannot express "remove this variant", and the service refuses to delete a
 * variant that an order already references. Variant ids are optional keys inside
 * `variants`, so an unchanged variant is still recognised.
 */
class UpdateProductRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->prepareSlug();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = $this->route('product');
        $ignoreId = $product instanceof Product ? $product->getKey() : null;

        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'alpha_dash', $this->tenantUnique('products', 'slug', $ignoreId)],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'highlights' => ['sometimes', 'nullable', 'array', 'max:20'],
            'highlights.*' => ['string', 'max:300'],
            'features' => ['sometimes', 'nullable', 'array', 'max:40'],
            'features.*' => ['string', 'max:300'],
            'specifications' => ['sometimes', 'nullable', 'array', 'max:60'],
            'specifications.*.label' => ['required', 'string', 'max:80'],
            'specifications.*.value' => ['nullable', 'string', 'max:300'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:80'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'seo_keywords' => ['sometimes', 'nullable', 'string', 'max:500'],
            'translations' => ['sometimes', 'array', 'max:25'],
            'translations.*' => ['array'],
            'translations.*.*' => ['nullable', 'string', 'max:100000'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'is_taxable' => ['sometimes', 'boolean'],
            'category_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Category::class)],
            'store_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Store::class)],
            'variants' => ['sometimes', 'array', 'min:1', 'max:50'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.description' => ['nullable', 'string', 'max:2000'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
        ];
    }
}
