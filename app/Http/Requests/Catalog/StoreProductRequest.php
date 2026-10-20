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
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3', 'uppercase'],
            'is_taxable' => ['sometimes', 'boolean'],
            'category_id' => ['nullable', 'integer', $this->tenantExists(Category::class)],
            'store_id' => ['nullable', 'integer', $this->tenantExists(Store::class)],
            'variants' => ['sometimes', 'array', 'max:50'],
            'variants.*.name' => ['required', 'string', 'max:120'],
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
