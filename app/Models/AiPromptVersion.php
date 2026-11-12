<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of a prompt template.
 *
 * Versions are append-only. `variables` is the whitelist of placeholders the
 * prompt may carry; rendering interpolates only those keys, so a caller can
 * never smuggle an unlisted (or arbitrary) substitution into a trusted
 * instruction.
 */
#[Fillable([
    'template_id',
    'version',
    'locale',
    'system_prompt',
    'user_prompt',
    'variables',
    'created_by',
    'change_note',
    'created_at',
])]
class AiPromptVersion extends Model
{
    protected $table = 'ai_prompt_versions';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<AiPromptTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AiPromptTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'variables' => 'array',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
