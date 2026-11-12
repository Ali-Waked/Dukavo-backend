<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Http\Requests\ApiRequest;

/**
 * Updating a channel connection.
 *
 * A blank credential means "leave the stored value alone". The channel itself
 * is immutable: moving a connection to another provider would invalidate every
 * message id already stored against it.
 */
class UpdateIntegrationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'store_id' => ['sometimes', 'nullable', 'integer', $this->tenantExists('stores')],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
