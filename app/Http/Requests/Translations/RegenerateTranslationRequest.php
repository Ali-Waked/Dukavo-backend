<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * Regenerating one row. `confirm_overwrite` must be true when the current copy
 * was edited by a person or already approved.
 */
class RegenerateTranslationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_overwrite' => ['sometimes', 'boolean'],
            'protected_terms' => ['sometimes', 'array', 'max:50'],
            'protected_terms.*' => ['required', 'string', 'max:120'],
        ];
    }
}
