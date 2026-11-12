<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider identity belonging to a customer.
 *
 * `(tenant_id, channel, external_id)` is unique, which makes inbound webhook
 * handling idempotent: the same Telegram chat or Instagram user always resolves
 * to the same identity. Linking two identities to one customer is explicit —
 * the resolver never merges on a name or username match.
 */
#[Fillable([
    'tenant_id', 'customer_id', 'channel', 'external_id', 'username', 'phone',
    'email', 'display_name', 'verified_at', 'consent_at', 'opted_out_at', 'meta',
])]
class CustomerIdentity extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'consent_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }
}
