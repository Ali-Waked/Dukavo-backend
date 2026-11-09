<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationRunLogLevel;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured, append-only line explaining what an automation run did.
 *
 * Logs are deliberately short and machine-readable: they name the phase
 * (queued, matched, action, retry) so a merchant can see why a rule skipped or
 * which integration was unavailable.
 */
#[Fillable([
    'tenant_id', 'automation_run_id', 'level', 'message', 'context', 'created_at',
])]
class AutomationRunLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'level' => AutomationRunLogLevel::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }
}
