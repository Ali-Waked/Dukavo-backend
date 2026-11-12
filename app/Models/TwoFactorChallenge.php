<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short-lived, single-use second-factor challenge.
 *
 * The plaintext token never reaches the database: `token_hash` is a SHA-256
 * digest. The row is not an authentication credential by itself; it only permits
 * one attempt to exchange a valid TOTP or recovery code for a real token.
 */
#[Fillable(['user_id', 'token_hash', 'expires_at', 'consumed_at', 'attempts', 'ip_address', 'user_agent'])]
class TwoFactorChallenge extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
