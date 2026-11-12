<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One provider identity attached to a customer.
 *
 * The external id is shown because it is the merchant's own contact handle on
 * the provider (a Telegram chat id, an Instagram user id), not a secret.
 *
 * @property-read CustomerIdentity $resource
 */
class CustomerIdentityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'channel' => $this->channel,
            'external_id' => $this->external_id,
            'username' => $this->username,
            'phone' => $this->phone,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'consent_at' => $this->consent_at?->toIso8601String(),
            'opted_out_at' => $this->opted_out_at?->toIso8601String(),
            'is_opted_out' => $this->isOptedOut(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
