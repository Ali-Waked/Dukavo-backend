<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiActionClassification;
use App\Enums\AiActionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business action an AI assistant proposed.
 *
 * The row is the boundary between a suggestion and a fact. A model can only
 * ever create one of these in the `draft` state; the payload it proposes is
 * not trusted and is resolved against real records before anything is acted on.
 * Only an approved action may be executed, and `idempotency_key` guarantees
 * that executing it twice has the same effect as executing it once.
 *
 * The model is tenant-scoped through {@see BelongsToTenant}, so an action
 * belonging to one merchant can never be read or executed inside another.
 */
#[Fillable([
    'tenant_id',
    'user_id',
    'approved_by',
    'confirmed_by',
    'executed_by',
    'use_case',
    'action_type',
    'classification',
    'status',
    'summary',
    'payload',
    'resolved_payload',
    'result',
    'rejection_reason',
    'error',
    'idempotency_key',
    'submitted_at',
    'approved_at',
    'confirmed_at',
    'rejected_at',
    'executed_at',
    'expires_at',
])]
class AiAction extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => AiActionStatus::class,
            'classification' => AiActionClassification::class,
            'payload' => 'array',
            'resolved_payload' => 'array',
            'result' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'approved_by' => 'integer',
            'confirmed_by' => 'integer',
            'executed_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The person who proposed the action.
     *
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The person who authorised it.
     *
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The person who supplied the explicit confirmation, when one was required.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * The person who ran it.
     *
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * Whether the action may still be executed.
     */
    public function isExecutable(): bool
    {
        return $this->status === AiActionStatus::Approved
            && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
