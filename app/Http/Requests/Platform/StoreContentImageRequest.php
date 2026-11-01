<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Http\Requests\ApiRequest;

/**
 * An image pasted or chosen inside a newsletter body.
 *
 * Only raster formats the browser can display safely are accepted. SVG is
 * deliberately excluded: an SVG is a document that can carry script, so serving
 * one from our own origin would turn an image upload into stored XSS. The size
 * ceiling matches the cover upload; inline images should be compressed, not
 * treated as an archive.
 */
class StoreContentImageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ];
    }
}
