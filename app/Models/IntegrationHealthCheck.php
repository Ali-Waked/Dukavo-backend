<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntegrationHealthState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last recorded outcome of a real integration probe.
 *
 * Only an explicit test (or a future scheduled check on verified
 * infrastructure) writes this row. Dashboard reads never perform a network
 * call, which is why loading the health page cannot spend provider budget.
 */
#[Fillable(['integration', 'state', 'summary', 'metadata', 'checked_at', 'failed_at', 'checked_by'])]
class IntegrationHealthCheck extends Model
{
    protected function casts(): array
    {
        return [
            'state' => IntegrationHealthState::class,
            'metadata' => 'array',
            'checked_at' => 'datetime',
            'failed_at' => 'datetime',
            'checked_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
