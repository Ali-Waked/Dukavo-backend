<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who buys from a tenant.
 *
 * Email and phone are deliberately not unique inside a tenant: the same person
 * may shop across stores, or be captured twice with slightly different
 * spellings. Deduplication is a deliberate, auditable action.
 */
#[Fillable(['tenant_id', 'store_id', 'name', 'email', 'phone', 'status', 'whatsapp_opt_out_at', 'locale', 'notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['name', 'email', 'phone', 'status', 'whatsapp_opt_out_at', 'locale'];
    }

    protected function casts(): array
    {
        return [
            'last_order_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
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
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<RecoveryOpportunity, $this>
     */
    public function recoveryOpportunities(): HasMany
    {
        return $this->hasMany(RecoveryOpportunity::class);
    }

    public function defaultAddress(): ?CustomerAddress
    {
        return $this->addresses->firstWhere('is_default', true) ?? $this->addresses->first();
    }

    /**
     * @return HasMany<CustomerMemory, $this>
     */
    public function memories(): HasMany
    {
        return $this->hasMany(CustomerMemory::class);
    }

    /**
     * @return HasOne<CustomerSummary, $this>
     */
    public function summary(): HasOne
    {
        return $this->hasOne(CustomerSummary::class);
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, function (Builder $builder, string $value): void {
            $escaped = addcslashes($value, '%_\\');

            $builder->where(function (Builder $inner) use ($escaped): void {
                $inner->where('name', 'like', "%{$escaped}%")
                    ->orWhere('email', 'like', "%{$escaped}%")
                    ->orWhere('phone', 'like', "%{$escaped}%");
            });
        });
    }
}
