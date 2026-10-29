<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CartStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced proposal assembled from a conversation.
 *
 * Money columns are denormalised snapshots for fast rendering. They are always
 * recomputed from the catalogue by the cart service before a cart can be
 * confirmed; the browser never supplies a trusted total.
 */
#[Fillable([
    'tenant_id', 'conversation_id', 'store_id', 'customer_id', 'order_id', 'status',
    'currency', 'subtotal', 'discount_total', 'tax_total', 'shipping_total',
    'grand_total', 'tax_rate', 'shipping_address', 'notes', 'version',
    'confirmation_requested_at', 'confirmation_sent_at', 'confirmation_message_id',
    'confirmation_error',
])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'shipping_address' => 'array',
            'version' => 'integer',
            'confirmation_requested_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isConfirmable(): bool
    {
        return $this->status === CartStatus::PendingConfirmation && $this->items()->exists();
    }
}
