<?php

declare(strict_types=1);

namespace App\Http\Requests\Translations;

use App\Http\Requests\ApiRequest;

/**
 * Approving the rows the merchant currently sees and selected.
 *
 * The ids are verified against the record in the service, so this is never a
 * way to approve an unseen translation from another record.
 */
class ApproveTranslationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
