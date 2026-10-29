<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Contracts\Cache\Repository;

/**
 * A short-lived record of work that must not happen twice.
 *
 * Queue delivery is at-least-once: the same job can be handed to a worker more
 * than once, and a user can double-submit a form. This store lets a use case
 * recognise a repeat and skip it, so an AI-derived change is applied once even
 * when the trigger arrives twice.
 *
 * The value is only a marker, never a secret or a prompt.
 */
final class IdempotencyStore
{
    private const PREFIX = 'ai:idempotency:';

    private readonly Repository $cache;

    public function __construct(?Repository $cache = null)
    {
        $this->cache = $cache ?? app('cache.store');
    }

    public function has(string $key): bool
    {
        return $this->cache->has(self::PREFIX.$key);
    }

    /**
     * @param  array<string, mixed>  $result  What the work returned, for replay.
     */
    public function store(string $key, array $result, ?int $ttlMinutes = null): void
    {
        $ttl = $ttlMinutes ?? (int) config('ai.governance.idempotency_ttl_minutes', 60);

        $this->cache->put(self::PREFIX.$key, $result, now()->addMinutes($ttl));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $value = $this->cache->get(self::PREFIX.$key);

        return is_array($value) ? $value : null;
    }
}
