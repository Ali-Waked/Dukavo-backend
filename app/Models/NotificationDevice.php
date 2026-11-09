<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A push destination owned by one user.
 *
 * Tokens are per installation, never per account: signing in on a second phone
 * must not redirect the first phone's notifications. A token that Firebase
 * rejects is revoked rather than deleted so the history of why a device went
 * quiet survives.
 */
#[Fillable([
    'user_id', 'device_id', 'platform', 'token', 'token_hash', 'device_name',
    'app_version', 'locale', 'last_seen_at', 'revoked_at',
])]
class NotificationDevice extends Model
{
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function touchSeen(): void
    {
        $this->forceFill(['last_seen_at' => now(), 'revoked_at' => null])->save();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
