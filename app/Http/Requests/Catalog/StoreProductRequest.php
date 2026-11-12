<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Enums\ProductStatus;
use App\Http\Requests\ApiRequest;
use App\Models\Category;
use App\Models\Store;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a product with its variants.
 *
 * `variants` may be omitted, in which case the service creates a single default
 * variant — every product must be sellable, and a product with no variant cannot
 * be ordered or stocked. Unit prices are required per variant because they are
 * the source of truth for order pricing.
 */
class StoreProductRequest extends ApiRequest
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
        return [
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', $this->tenantUnique('products', 'slug')],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:2000'],
            'highlights' => ['sometimes', 'nullable', 'array', 'max:20'],
            'highlights.*' => ['string', 'max:300'],
            'features' => ['sometimes', 'nullable', 'array', 'max:40'],
            'features.*' => ['string', 'max:300'],
            'specifications' => ['sometimes', 'nullable', 'array', 'max:60'],
            'specifications.*.label' => ['required', 'string', 'max:80'],
            'specifications.*.value' => ['nullable', 'string', 'max:300'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:80'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'string', 'max:500'],
            'translations' => ['sometimes', 'array', 'max:25'],
            'translations.*' => ['array'],
            'translations.*.*' => ['nullable', 'string', 'max:100000'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase', Rule::in(array_keys((array) config('dukavo.currencies.supported', [])))],
            'is_taxable' => ['sometimes', 'boolean'],
            'category_id' => ['nullable', 'integer', $this->tenantExists(Category::class)],
            'store_id' => ['nullable', 'integer', $this->tenantExists(Store::class)],
            'variants' => ['sometimes', 'array', 'max:50'],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.description' => ['nullable', 'string', 'max:2000'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
            // Opening stock for the variant, recorded as an `initial` ledger
            // movement in the product's store (or the tenant default) at
            // creation. Zero still creates the inventory row.
            'variants.*.initial_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $variants = $this->input('variants');

            if (! is_array($variants) || $variants === []) {
                return;
            }

            // The service guarantees exactly one default variant even when the
            // payload nominates none, but saying it here produces a far clearer
            // error than a silently chosen default.
            $defaults = collect($variants)->filter(fn (array $variant): bool => (bool) ($variant['is_default'] ?? false));

            if ($defaults->count() > 1) {
                $validator->errors()->add('variants', 'Only one variant can be the default.');
            }
        });
    }
}
