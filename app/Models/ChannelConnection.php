<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelHealth;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant-owned connection to one communication provider.
 *
 * The row stores only what the provider gave us: an account identifier and an
 * encrypted credential blob. It never stores a customer message. The connector
 * for `channel` interprets the credential blob, so a new provider is a new
 * connector rather than a schema change.
 */
#[Fillable([
    'tenant_id', 'store_id', 'channel', 'provider', 'name', 'external_account_id',
    'display_name', 'credentials', 'capabilities', 'status', 'health',
    'error_message', 'connected_at', 'last_synced_at', 'last_health_check_at', 'meta',
])]
#[Hidden(['credentials'])]
class ChannelConnection extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'meta' => 'array',
            'status' => ChannelConnectionStatus::class,
            'health' => ChannelHealth::class,
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_health_check_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isUsable(): bool
    {
        return $this->status === ChannelConnectionStatus::Connected;
    }

    /**
     * A masked view of the credential names only, for the UI. Values are never
     * included; the merchant can see which fields exist, not what they are.
     *
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        $credentials = $this->credentials;

        return is_array($credentials) ? array_keys($credentials) : [];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', ChannelConnectionStatus::Connected->value);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForChannel(Builder $query, string $channel): void
    {
        $query->where('channel', $channel);
    }
}
