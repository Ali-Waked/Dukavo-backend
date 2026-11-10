<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Currency\CurrencyCatalog;
use InvalidArgumentException;

/**
 * Decimal-safe money arithmetic.
 *
 * Payment amounts must never pass through a binary float: KWD has three minor
 * units and a provider compares the amount string it was sent, not an
 * approximation. Every operation here works on minor units with bcmath and
 * rounds half-up to the currency's own precision.
 */
final class Money
{
    private const WORKING_SCALE = 10;

    public static function decimals(string $currency): int
    {
        return app(CurrencyCatalog::class)->decimals(strtoupper($currency));
    }

    /**
     * Round an amount to the currency's minor unit and return a plain string.
     */
    public static function normalize(mixed $amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        $factor = bcpow('10', (string) $decimals, 0);

        return bcdiv(self::toMinor($amount, $currency), $factor, $decimals);
    }

    /**
     * The amount expressed in integer minor units, rounded half-up.
     */
    public static function toMinor(mixed $amount, string $currency): string
    {
        $decimals = self::decimals($currency);
        $factor = bcpow('10', (string) $decimals, 0);
        $scaled = bcmul(self::plain($amount), $factor, self::WORKING_SCALE);
        $adjustment = str_starts_with($scaled, '-') ? '-0.5' : '0.5';

        return bcadd($scaled, $adjustment, 0);
    }

    public static function fromMinor(string|int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);
        $factor = bcpow('10', (string) $decimals, 0);

        return bcdiv((string) $minor, $factor, $decimals);
    }

    public static function add(mixed $left, mixed $right, string $currency): string
    {
        return self::fromMinor(
            bcadd(self::toMinor($left, $currency), self::toMinor($right, $currency), 0),
            $currency,
        );
    }

    public static function subtract(mixed $left, mixed $right, string $currency): string
    {
        return self::fromMinor(
            bcsub(self::toMinor($left, $currency), self::toMinor($right, $currency), 0),
            $currency,
        );
    }

    /**
     * -1, 0 or 1. Used for "is this refund still within the charge?".
     */
    public static function compare(mixed $left, mixed $right, string $currency): int
    {
        return bccomp(self::toMinor($left, $currency), self::toMinor($right, $currency), 0);
    }

    public static function equals(mixed $left, mixed $right, string $currency): bool
    {
        return self::compare($left, $right, $currency) === 0;
    }

    public static function isPositive(mixed $amount, string $currency): bool
    {
        return self::compare($amount, '0', $currency) === 1;
    }

    public static function min(mixed $left, mixed $right, string $currency): string
    {
        return self::compare($left, $right, $currency) <= 0
            ? self::normalize($left, $currency)
            : self::normalize($right, $currency);
    }

    /**
     * Convert a float or numeric string into a plain decimal string bcmath can
     * read. Scientific notation from a PHP float is expanded rather than
     * rejected, because order columns arrive as casts.
     */
    private static function plain(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value !== '' && preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
                return $value;
            }
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('A monetary amount must be numeric.');
        }

        $formatted = rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
