<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the effective value of one setting came from.
 *
 * The distinction is shown in the dashboard so an operator never assumes a
 * database value won when the deployment environment is the real source.
 */
enum ConfigurationSource: string
{
    case Database = 'database';
    case Environment = 'environment';
    case Default = 'default';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Platform setting',
            self::Environment => 'Environment variable',
            self::Default => 'Application default',
        };
    }
}
