<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Models\AiProviderCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credential as an operator may see it.
 *
 * The API key itself is never in this payload — only the masked hint — and
 * the resource exists so that is enforced structurally: there is no field
 * here that could ever carry the plaintext or the ciphertext.
 *
 * @property-read AiProviderCredential $resource
 */
class AiProviderCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = is_array($this->resource->config) ? $this->resource->config : [];
        $model = $config['model'] ?? null;

        return [
            'id' => $this->resource->id,
            'provider_id' => $this->resource->provider_id,
            'tenant_id' => $this->resource->tenant_id,
            'owner' => $this->resource->owner()->value,
            'masked_key' => $this->resource->maskedKey(),
            'base_url' => $this->resource->base_url,
            'model' => is_string($model) && $model !== '' ? $model : null,
            'last_checked_at' => $this->resource->last_checked_at?->toIso8601String(),
            'last_check_status' => $this->resource->last_check_status,
            'revoked_at' => $this->resource->revoked_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
