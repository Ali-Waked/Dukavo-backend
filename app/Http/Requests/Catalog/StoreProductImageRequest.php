<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\ApiRequest;

/**
 * Uploading images to a product's gallery.
 *
 * Content is verified with `mimes` rather than the `image` rule so the endpoint
 * stays usable on PHP builds without GD (the same choice as the avatar upload).
 * Files are stored as-is; Dukavo ships no image processing library.
 */
class StoreProductImageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'alt_text' => ['sometimes', 'array'],
            'alt_text.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
