<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecoveryOpportunityStatus;
use App\Enums\RecoveryReason;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One detected, reviewable lost-sale opportunity.
 *
 * It is derived state, not a replacement for the conversation, cart or order.
 * Agents work it here; the underlying records remain the source of truth, and
 * recovered revenue is always the linked order's own total.
 */
#[Fillable([
    'tenant_id', 'store_id', 'conversation_id', 'customer_id', 'cart_id', 'order_id',
    'assigned_to', 'dismissed_by', 'status', 'reason', 'dedupe_key', 'estimated_value',
    'currency', 'contact_attempts', 'detected_at', 'last_activity_at', 'last_contacted_at',
    'responded_at', 'next_follow_up_at', 'recovered_at', 'dismissed_at', 'expires_at',
    'dismissal_reason', 'meta',
])]
class RecoveryOpportunity extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['status', 'reason', 'assigned_to', 'order_id', 'estimated_value'];
    }

    protected function casts(): array
    {
        return [
            'status' => RecoveryOpportunityStatus::class,
            'reason' => RecoveryReason::class,
            'estimated_value' => 'decimal:2',
            'contact_attempts' => 'integer',
            'detected_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'responded_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'recovered_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
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
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    /**
     * @return HasMany<RecoveryEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RecoveryEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isContactable(): bool
    {
        return in_array($this->status, [
            RecoveryOpportunityStatus::Eligible,
            RecoveryOpportunityStatus::Contacted,
            RecoveryOpportunityStatus::Snoozed,
        ], true);
    }

    public function isRecovered(): bool
    {
        return $this->status === RecoveryOpportunityStatus::Recovered;
    }

    /**
     * Value that can still be recovered, excluding final records.
     */
    public function openValue(): float
    {
        return $this->status->isOpen() ? (float) ($this->estimated_value ?? 0) : 0.0;
    }

    /**
     * @return Builder<self>
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', [
            RecoveryOpportunityStatus::Eligible->value,
            RecoveryOpportunityStatus::Contacted->value,
            RecoveryOpportunityStatus::Snoozed->value,
        ]);
    }

    /**
     * @return Builder<self>
     */
    #[Scope]
    protected function withStatus(Builder $query, RecoveryOpportunityStatus|string $status): void
    {
        $query->where('status', $status instanceof RecoveryOpportunityStatus ? $status->value : $status);
    }
}
