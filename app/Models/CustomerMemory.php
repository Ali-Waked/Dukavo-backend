<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'customer_id',
    'created_by',
    'updated_by',
    'key',
    'value',
    'category',
    'source',
    'confidence',
    'meta',
    'expires_at',
    'review_at',
])]
class CustomerMemory extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected function auditAttributes(): array
    {
        return ['key', 'value', 'category', 'source'];
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'review_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
