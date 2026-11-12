<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a detected preference came from.
 *
 * `Verified` is reserved for values the user explicitly confirmed or that an
 * authoritative source supplied. IP-based country and city are estimates and
 * must never be presented as verified personal information.
 */
enum PreferenceSource: string
{
    case Ip = 'ip';
    case Device = 'device';
    case Browser = 'browser';
    case Manual = 'manual';
    case Default = 'default';
    case Existing = 'existing';

    public function label(): string
    {
        return match ($this) {
            self::Ip => 'Estimated from network',
            self::Device => 'Detected from device',
            self::Browser => 'Detected from browser',
            self::Manual => 'Entered by you',
            self::Default => 'Platform default',
            self::Existing => 'Saved previously',
        };
    }
}
