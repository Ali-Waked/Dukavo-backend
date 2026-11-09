<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in an account's marketing-consent history.
 *
 * Security and operational notices are not recorded here: they are not
 * consent-based and cannot be switched off. Only optional categories such as
 * `product_news` produce a row.
 */
#[Fillable([
    'user_id', 'email', 'channel', 'category', 'action', 'source',
    'ip_address', 'user_agent', 'metadata',
])]
class NotificationConsent extends Model
{
    public const string GRANTED = 'granted';

    public const string REVOKED = 'revoked';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
