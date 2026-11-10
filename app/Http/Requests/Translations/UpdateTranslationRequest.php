<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * A merchant editing a proposed translation by hand.
 *
 * An edit always returns the row to review: it is a new proposal, not an
 * approval, and it marks the copy as human-authored so regeneration cannot
 * silently replace it.
 */
class UpdateTranslationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'translated_text' => ['required', 'string', 'max:100000'],
        ];
    }
}
