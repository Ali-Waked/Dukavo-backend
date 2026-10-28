<?php

declare(strict_types=1);

namespace App\Http\Requests\Bundle;

use App\Enums\BundleDiscountType;
use App\Enums\BundleStatus;
use App\Http\Requests\ApiRequest;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBundleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(BundleStatus::class)],
            'discount_type' => ['sometimes', Rule::enum(BundleDiscountType::class)],
            'discount_value' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            'store_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists(Store::class)],
            'items' => ['sometimes', 'array', 'min:2', 'max:50'],
            'items.*.product_id' => ['required', 'integer', $this->tenantExists('products')],
            'items.*.product_variant_id' => ['required', 'integer', $this->tenantExists('product_variants')],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateVariantOwnership($validator);
        });
    }

    private function validateVariantOwnership(Validator $validator): void
    {
        foreach ((array) $this->input('items', []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = $item['product_id'] ?? null;
            $variantId = $item['product_variant_id'] ?? null;

            if (! is_numeric($productId) || ! is_numeric($variantId)) {
                continue;
            }

            $belongs = ProductVariant::query()
                ->whereKey((int) $variantId)
                ->where('product_id', (int) $productId)
                ->exists();

            if (! $belongs) {
                $validator->errors()->add(
                    "items.{$index}.product_variant_id",
                    'The selected variant does not belong to the selected product.',
                );
            }
        }
    }
}
