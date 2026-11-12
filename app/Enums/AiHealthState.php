<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The health of a provider credential, as the circuit breaker sees it.
 *
 * `Healthy` — served recently, calls go through.
 * `Degraded` — failed recently but below the breaker threshold; calls go
 * through and failures still count.
 * `Open` — the circuit breaker has tripped: the provider is skipped until its
 * cooldown expires, so a failing upstream cannot consume every request.
 */
enum AiHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Open = 'open';

    public function allowsCalls(): bool
    {
        return $this !== self::Open;
    }
}
