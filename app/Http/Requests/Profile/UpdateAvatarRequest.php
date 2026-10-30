<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;
use Closure;
use Illuminate\Http\UploadedFile;

class UpdateAvatarRequest extends ApiRequest
{
    private const MAX_DIMENSION = 8192;

    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        $fail('The avatar must be a valid image file.');

                        return;
                    }

                    $path = $value->getRealPath();
                    $dimensions = $path === false ? false : @getimagesize($path);

                    if ($dimensions === false) {
                        $fail('The avatar must be a readable JPG, PNG or WebP image.');

                        return;
                    }

                    [$width, $height, $type] = $dimensions;

                    if (! in_array($type, self::ALLOWED_TYPES, true)) {
                        $fail('The avatar must be a JPG, PNG or WebP image.');

                        return;
                    }

                    if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                        $fail('The avatar dimensions are not supported.');
                    }
                },
            ],
        ];
    }
}
