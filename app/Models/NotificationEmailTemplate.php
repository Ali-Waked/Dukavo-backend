<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform operator's overrides for one branded email template.
 *
 * The catalogue defines which templates exist and what their defaults are. This
 * row only carries the deltas, per locale, so removing it restores the shipped
 * copy without losing anything else.
 */
#[Fillable(['template_key', 'enabled', 'subject', 'heading', 'intro', 'updated_by'])]
class NotificationEmailTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'subject' => 'array',
            'heading' => 'array',
            'intro' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
