<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * A request to generate AI translations for one record.
 *
 * Only field keys and locale codes are accepted; the source text always comes
 * from the record on the server, never from the request. That is what stops a
 * caller from machine-translating arbitrary text through the merchant's AI
 * quota.
 */
class GenerateTranslationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', 'max:80'],
            'target_locales' => ['sometimes', 'array'],
            'target_locales.*' => ['required', 'string', 'max:12'],
            'source_locale' => ['sometimes', 'nullable', 'string', 'max:12'],
            'confirm_overwrite' => ['sometimes', 'boolean'],
            'protected_terms' => ['sometimes', 'array', 'max:50'],
            'protected_terms.*' => ['required', 'string', 'max:120'],
        ];
    }
}
