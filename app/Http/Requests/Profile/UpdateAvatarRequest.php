<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Http\Requests\ApiRequest;

/**
 * Avatar upload.
 *
 * Bounded to 2 MB and to the formats every browser renders natively (including
 * webp). The file is stored as-is — Dukavo ships no image processing library, so
 * promising a resized thumbnail would be a promise the code cannot keep. Content
 * is verified through MIME sniffing rather than the `image` rule: that rule
 * needs GD's getimagesize, which renders the endpoint untestable (and
 * un-servable) on GD-less PHP builds.
 */
class UpdateAvatarRequest extends ApiRequest
{
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
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
