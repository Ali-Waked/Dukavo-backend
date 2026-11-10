<?php

declare(strict_types=1);

namespace App\Http\Resources\Payments;

use App\Models\StorePaymentAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant-safe connection view.
 *
 * Credentials and the webhook secret are deliberately absent: only their
 * existence is reported so the interface can say whether a key is stored.
 *
 * @property-read StorePaymentAccount $resource
 */
class PaymentConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'provider' => [
                'code' => $this->provider?->code,
                'name' => $this->provider?->name,
            ],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'environment' => $this->environment,
            'merchant_reference' => $this->merchant_reference,
            'has_credentials' => $this->hasCredentials(),
            'has_webhook_secret' => trim((string) $this->webhook_secret) !== '',
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
