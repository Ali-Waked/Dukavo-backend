<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Http\Requests\ApiRequest;

/**
 * Reordering a product gallery. The payload is the full list of image ids in the
 * desired order; ids the product does not own are ignored by the service.
 */
class ReorderProductImageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['integer'],
        ];
    }
}
