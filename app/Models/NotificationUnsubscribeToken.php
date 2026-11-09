<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A hashed, single-purpose unsubscribe link.
 *
 * The raw token is returned exactly once by {@see self::issue()} and is never
 * persisted. Lookups compare the SHA-256 hash, so a database leak does not hand
 * an attacker working unsubscribe links.
 */
#[Fillable(['token_hash', 'user_id', 'channel', 'category', 'expires_at', 'used_at'])]
class NotificationUnsubscribeToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Create a token and return the only copy of its plain value.
     */
    public static function issue(
        User $user,
        string $channel = 'email',
        string $category = 'product_news',
        ?int $ttlDays = null,
    ): self {
        $plain = Str::random(64);
        $ttlDays ??= (int) config('dukavo.email.unsubscribe_ttl_days', 90);

        $token = self::query()->create([
            'token_hash' => self::hash($plain),
            'user_id' => $user->getKey(),
            'channel' => $channel,
            'category' => $category,
            'expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
        ]);

        // The plain value is deliberately not an attribute, so it can never be
        // serialised into JSON or accidentally logged with the model.
        $token->plainTextToken = $plain;

        return $token;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findByPlainText(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return self::query()->where('token_hash', self::hash($plain))->first();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plain token for the current request only. Not persisted, not cast.
     */
    public ?string $plainTextToken = null;
}
