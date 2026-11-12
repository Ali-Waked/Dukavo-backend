<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Http\Requests\ApiRequest;
use App\Services\Channels\ChannelCapabilityRegistry;
use Illuminate\Validation\Rule;

/**
 * Explicitly linking a provider identity to an existing customer.
 *
 * This is the only way two identities become one customer. The resolver never
 * merges on a display name or username, because neither proves who is writing.
 */
class LinkCustomerIdentityRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(ChannelCapabilityRegistry::keys())],
            'external_id' => ['required', 'string', 'max:191'],
            'username' => ['nullable', 'string', 'max:190'],
            'display_name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
        ];
    }
}
