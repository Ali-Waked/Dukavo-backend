<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ai\Support\PromptGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned prompt template, one per task and purpose.
 *
 * The rendered prompt is always the active version; editing creates a new
 * version row rather than mutating one, and activation is moving the pointer.
 * Nothing here is ever exposed to a caller without authorisation — the
 * system prompt is a trusted instruction, and user content is only ever
 * interpolated inside the {@see PromptGuard}
 * delimiters, never concatenated into the instruction itself.
 */
#[Fillable([
    'task_id',
    'key',
    'name',
    'description',
    'active_version_id',
])]
class AiPromptTemplate extends Model
{
    protected $table = 'ai_prompt_templates';

    /**
     * @return BelongsTo<AiTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AiTask::class);
    }

    /**
     * @return HasMany<AiPromptVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AiPromptVersion::class, 'template_id')
            ->orderByDesc('version');
    }

    /**
     * @return BelongsTo<AiPromptVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'active_version_id');
    }

    protected function casts(): array
    {
        return [
            'active_version_id' => 'integer',
        ];
    }
}
