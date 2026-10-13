<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately exposes no credential material. `access_token` is encrypted at
 * rest and is never serialised, so a leaked screenshot of the settings page
 * cannot leak the account.
 *
 * @property-read WhatsappAccount $resource
 */
class WhatsappAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'name' => $this->name,
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_connected' => $this->isConnected(),
            'is_usable' => $this->isUsable(),
            'error_message' => $this->error_message,
            // Unlike the access token this is not a credential: the merchant has
            // to copy it into Meta's dashboard to finish the handshake, and it
            // grants no access to the account on its own.
            'webhook_verify_token' => $this->webhook_verify_token,
            'meta' => $this->meta,
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
