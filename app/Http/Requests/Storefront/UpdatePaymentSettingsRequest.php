<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\ApiRequest;

/**
 * Store payment settings.
 *
 * Only cash on delivery is configurable because it is the only method the
 * platform can currently honour. Online payment is reported as unavailable by
 * the service rather than exposed as a setting that would do nothing.
 */
class UpdatePaymentSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cod_enabled' => ['sometimes', 'boolean'],
            'cod_instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
