<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * A merchant saving copy from the inline per-language editor.
 *
 * The payload is keyed by locale then by field. Only the text is accepted: the
 * source copy always comes from the record on the server, so saving a
 * translation can never translate arbitrary text through the merchant's AI
 * quota. An omitted field is left untouched rather than cleared.
 */
class SaveTranslationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'translations' => ['required', 'array', 'min:1', 'max:25'],
            'translations.*' => ['required', 'array'],
            'translations.*.*' => ['nullable', 'string', 'max:100000'],
        ];
    }
}
