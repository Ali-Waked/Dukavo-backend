<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentConnectionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A merchant's provider connection for one store.
 *
 * `credentials` and `webhook_secret` are encrypted at rest and hidden from
 * serialisation. No API resource, audit snapshot or log line may include them.
 * A connection is only usable when its status is `active`, which is set by a
 * successful provider-side connection test.
 */
#[Fillable(['tenant_id', 'store_id', 'provider_id', 'status', 'environment', 'credentials', 'webhook_secret', 'merchant_reference', 'settings', 'last_verified_at', 'last_checked_at', 'last_error'])]
#[Hidden(['credentials', 'webhook_secret'])]
class StorePaymentAccount extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['status', 'environment', 'merchant_reference', 'last_verified_at'];
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentConnectionStatus::class,
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'settings' => 'array',
            'last_verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<PaymentProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return $this->status === PaymentConnectionStatus::Active
            && $this->last_verified_at !== null
            && $this->hasCredentials();
    }

    public function hasCredentials(): bool
    {
        $credentials = $this->credentials ?? [];

        return is_array($credentials) && trim((string) ($credentials['secret_key'] ?? '')) !== '';
    }

    public function secretKey(): string
    {
        return trim((string) (($this->credentials ?? [])['secret_key'] ?? ''));
    }

    /**
     * The webhook secret, falling back to the API secret only when a provider
     * documents that both are the same value.
     */
    public function webhookSecret(): string
    {
        $webhook = trim((string) ($this->webhook_secret ?? ''));

        return $webhook !== '' ? $webhook : $this->secretKey();
    }

    public function isSandbox(): bool
    {
        return $this->environment === 'sandbox';
    }
}
