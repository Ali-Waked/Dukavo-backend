<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a template at a point in time.
 *
 * Every published template change creates a version row. Installations record
 * the version they were created from, which is what lets the platform show
 * "update available" without guessing.
 */
#[Fillable([
    'automation_template_id', 'version', 'name', 'description', 'category',
    'trigger', 'conditions', 'actions', 'configuration_schema', 'checksum',
    'changelog', 'created_by',
])]
class AutomationTemplateVersion extends Model
{
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'configuration_schema' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AutomationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AutomationTemplate::class, 'automation_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
