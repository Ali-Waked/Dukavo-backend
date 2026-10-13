<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Orders\OrderService;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer order.
 *
 * Money columns are `decimal(12,2)` and are always recomputed on the server by
 * {@see OrderService}; a client payload can influence
 * quantities and discounts but never the resulting totals.
 *
 * Product references on the order items are nullable and the item snapshots
 * name/SKU, so historic orders stay readable even after the catalogue changes.
 */
#[Fillable(['tenant_id', 'store_id', 'customer_id', 'order_number', 'status', 'payment_status', 'currency', 'customer_name', 'customer_phone', 'shipping_address', 'notes'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return ['order_number', 'status', 'payment_status', 'grand_total'];
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'shipping_address' => 'array',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * Convenience accessor used by list views and the dashboard.
     */
    protected function itemCount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->items->sum('quantity'));
    }

    public function isCancellable(): bool
    {
        return ! $this->status->isFinal();
    }

    /**
     * Whether the lines of this order may still be rewritten.
     *
     * Deliberately narrower than {@see isCancellable()}: an order can still be
     * cancelled while it is being processed, but changing what was ordered after
     * picking has started would leave the warehouse record inconsistent with the
     * paperwork.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Confirmed], true);
    }

    /**
     * @return Builder<self>
     */
    #[Scope]
    protected function withStatus(Builder $query, OrderStatus|string $status): void
    {
        $query->where('status', $status instanceof OrderStatus ? $status->value : $status);
    }

    /**
     * @return Builder<self>
     */
    #[Scope]
    protected function placedBetween(Builder $query, string $from, string $until): void
    {
        $query->whereBetween('placed_at', [$from, $until]);
    }
}
