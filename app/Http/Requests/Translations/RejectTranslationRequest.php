<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * Rejecting a translation with an optional reason for the audit trail.
 */
class RejectTranslationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
