<?php

declare(strict_types=1);

namespace App\Http\Requests\Channels;

use App\Http\Requests\ApiRequest;
use App\Models\Store;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Connecting a WhatsApp Cloud API account.
 *
 * Either a phone number id or an access token is enough to begin: a merchant may
 * paste the token first and the id later. The pair is verified with Meta before
 * any message is sent, so half-filled credentials cannot break delivery.
 */
class ConnectWhatsappAccountRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['nullable', 'integer', Rule::exists(Store::class, 'id')],
            'name' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'business_account_id' => ['nullable', 'string', 'max:64'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'webhook_verify_token' => ['nullable', 'string', 'max:190'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('phone_number_id') && ! $this->filled('access_token')) {
                $validator->errors()->add('phone_number_id', 'A phone number id or an access token is required.');
            }
        });
    }
}
