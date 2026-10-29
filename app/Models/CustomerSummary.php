<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'customer_id',
    'generated_by',
    'summary',
    'highlights',
    'risks',
    'opportunities',
    'source_context',
    'model',
    'is_edited',
    'edited_by',
    'edited_at',
    'refreshed_at',
])]
class CustomerSummary extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected function auditAttributes(): array
    {
        return ['summary'];
    }

    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'risks' => 'array',
            'opportunities' => 'array',
            'source_context' => 'array',
            'is_edited' => 'boolean',
            'edited_at' => 'datetime',
            'refreshed_at' => 'datetime',
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
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
