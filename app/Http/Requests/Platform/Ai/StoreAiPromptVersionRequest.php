<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Ai;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * Creating the next version of a prompt template.
 *
 * Versions are append-only; `activate` decides whether the template's
 * pointer moves to the new version immediately. The variable whitelist is
 * part of the version, so a version cannot be activated whose placeholders
 * were never declared.
 */
final class StoreAiPromptVersionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in($this->supportedLocales())],
            'system_prompt' => ['required', 'string', 'max:20000'],
            'user_prompt' => ['nullable', 'string', 'max:20000'],
            'variables' => ['sometimes', 'array', 'max:50'],
            'variables.*' => ['string', 'max:60', 'alpha_dash'],
            'change_note' => ['nullable', 'string', 'max:500'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        $locales = config('dukavo.locales.supported');

        return is_array($locales) && $locales !== [] ? $locales : ['en'];
    }
}
