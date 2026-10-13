<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\ApiRequest;
use App\Models\Store;
use Illuminate\Validation\Rule;

/**
 * Editing a connected account. Every field is optional; an unchanged token is
 * never re-sent to Meta, and changing one puts the account back into the
 * verification queue.
 */
class UpdateWhatsappAccountRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Store::class, 'id')],
            'name' => ['sometimes', 'string', 'max:120'],
            'phone_number_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'business_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'webhook_verify_token' => ['sometimes', 'nullable', 'string', 'max:190'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
