<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only audit trail for one template installation.
 */
#[Fillable([
    'tenant_id', 'automation_template_installation_id', 'type', 'actor_id',
    'metadata', 'created_at',
])]
class AutomationInstallationEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationTemplateInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(AutomationTemplateInstallation::class, 'automation_template_installation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
