<?php

declare(strict_types=1);

namespace App\Services\Identity;

use DateTimeZone;

/**
 * IANA timezone validation and presentation.
 *
 * The configuration list is generated from PHP's own timezone database, so a
 * stale hand-written array cannot accept an identifier the runtime does not
 * know. The fallback is always UTC, never the server's local zone.
 */
final class TimezoneCatalog
{
    public const FALLBACK = 'UTC';

    /**
     * @return list<string>
     */
    public function all(): array
    {
        /** @var list<string> $timezones */
        $timezones = (array) config('timezones', []);

        return $timezones;
    }

    public function isValid(?string $timezone): bool
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            return false;
        }

        // The config list is the allow-list; the DateTimeZone check protects
        // against a config file that somehow contains an identifier the runtime
        // no longer recognises after a tzdata upgrade.
        return in_array($timezone, $this->all(), true)
            && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    public function resolve(?string $timezone): string
    {
        return $this->isValid($timezone) ? (string) $timezone : self::FALLBACK;
    }

    /**
     * @return list<array{value: string, label: string, region: string}>
     */
    public function options(): array
    {
        return array_map(static function (string $timezone): array {
            $parts = explode('/', $timezone, 2);

            return [
                'value' => $timezone,
                'label' => str_replace('_', ' ', $timezone),
                'region' => $parts[0],
            ];
        }, $this->all());
    }
}
