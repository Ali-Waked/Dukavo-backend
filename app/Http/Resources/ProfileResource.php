<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Currency\CurrencyCatalog;
use App\Support\PreferredLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account, as shown on the profile screen.
 *
 * Narrower than {@see UserResource}: no roles or tenants, because this describes
 * the person rather than their place in a workspace. `email` is read-only here
 * (changing it would need a verification workflow Dukavo does not have yet), and
 * `avatar_url` is built by the model so the resource never knows which disk
 * backs it.
 *
 * `preferred_currency` is the person's own display currency. It is deliberately
 * separate from any workspace or store currency: changing it never touches a
 * price, and it is only used by surfaces that are not scoped to a workspace.
 *
 * @property-read User $resource
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalog = app(CurrencyCatalog::class);
        $locale = PreferredLocale::from($request);
        $code = strtoupper((string) ($this->preferred_currency ?? $catalog->default()));

        if (! $catalog->supports($code)) {
            $code = $catalog->default();
        }

        $currency = $catalog->meta($code, $locale);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'preferred_currency' => $currency['code'],
            'preferred_currency_name' => $currency['name'],
            'preferred_currency_name_en' => $currency['name_en'],
            'preferred_currency_name_ar' => $currency['name_ar'],
            'preferred_currency_symbol' => $currency['symbol'],
            'preferred_currency_decimals' => $currency['decimals'],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'avatar_url' => $this->avatarUrl(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
