<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiHealthState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The circuit breaker's durable state for one provider.
 *
 * The breaker itself is an in-process object (fast, no I/O on the request
 * path); this row is what survives a restart and what the dashboard shows.
 * The breaker writes here opportunistically — a failure to persist never
 * blocks a request.
 */
#[Fillable([
    'provider_id',
    'state',
    'consecutive_failures',
    'failure_count',
    'opened_at',
    'cooldown_until',
    'last_error_code',
    'last_error_at',
    'last_success_at',
    'last_latency_ms',
    'last_checked_at',
    'last_check_status',
])]
class AiProviderHealth extends Model
{
    protected $table = 'ai_provider_health';

    /**
     * The provider row this health state belongs to.
     *
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    /**
     * Whether the provider may take calls right now.
     */
    public function allowsCalls(): bool
    {
        if ($this->state === AiHealthState::Open->value) {
            $until = $this->cooldown_until;

            return $until !== null && now()->gte($until);
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'state' => AiHealthState::class,
            'consecutive_failures' => 'integer',
            'failure_count' => 'integer',
            'opened_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'last_error_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_latency_ms' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }
}
