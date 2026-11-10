<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProviderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment provider catalogue entry.
 *
 * The row never carries a credential. `integration_enabled` is the platform's
 * honest statement about whether an adapter exists, while `status` is the
 * commercial state. Both are required before a provider can be offered.
 */
#[Fillable(['code', 'name', 'status', 'integration_enabled', 'adapter', 'docs_url', 'webhook_docs_url', 'notes', 'last_verified_at'])]
class PaymentProvider extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PaymentProviderStatus::class,
            'integration_enabled' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaymentProviderCountry, $this>
     */
    public function countries(): HasMany
    {
        return $this->hasMany(PaymentProviderCountry::class, 'provider_id');
    }

    /**
     * @return HasMany<PaymentProviderCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(PaymentProviderCapability::class, 'provider_id');
    }

    /**
     * @return HasMany<PaymentProviderMethod, $this>
     */
    public function methods(): HasMany
    {
        return $this->hasMany(PaymentProviderMethod::class, 'provider_id');
    }

    /**
     * @return HasMany<StorePaymentAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(StorePaymentAccount::class, 'provider_id');
    }

    public function isOffered(): bool
    {
        return $this->status === PaymentProviderStatus::Active && $this->integration_enabled;
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities->contains(fn (PaymentProviderCapability $row): bool => $row->capability === $capability);
    }
}
