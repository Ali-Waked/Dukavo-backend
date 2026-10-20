<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\ApiRequest;

/**
 * Editing a single gallery image's metadata. The file itself is immutable: a
 * replacement is an upload plus a delete, so a failed half-swap is impossible.
 */
class UpdateProductImageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
