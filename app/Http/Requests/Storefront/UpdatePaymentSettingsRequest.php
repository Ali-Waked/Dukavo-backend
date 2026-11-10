<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\ApiRequest;

/**
 * Store payment settings.
 *
 * Only the non-provider methods are configurable: cash on delivery and bank
 * transfer. Online payment is not a switch — it becomes available when a
 * verified provider connection exists, and is otherwise reported as
 * unavailable with the reason.
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
            'bank_transfer_enabled' => ['sometimes', 'boolean'],
            'bank_transfer_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
