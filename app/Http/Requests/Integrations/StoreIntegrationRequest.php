<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Http\Requests\ApiRequest;
use App\Services\Channels\ChannelCapabilityRegistry;
use Illuminate\Validation\Rule;

/**
 * Connecting one channel.
 *
 * The channel must exist in the capability registry, and the store must belong
 * to the caller's tenant. Credential keys are checked again by the manager
 * against the channel's declared fields, so an unknown key is a 422 rather than
 * a silent write into the encrypted blob.
 */
class StoreIntegrationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(ChannelCapabilityRegistry::keys())],
            'name' => ['nullable', 'string', 'max:120'],
            'store_id' => ['nullable', 'integer', $this->tenantExists('stores')],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
