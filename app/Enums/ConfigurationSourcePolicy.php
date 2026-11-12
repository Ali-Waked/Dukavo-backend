<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a definition may be sourced.
 *
 * `DatabasePreferred` is the ordinary integration setting: an operator may
 * override the environment, and clearing the row returns to it.
 * `EnvironmentOnly` is a security boundary, not a preference: the value is
 * read from the deployment environment and the API refuses to write it.
 */
enum ConfigurationSourcePolicy: string
{
    case DatabasePreferred = 'database_preferred';
    case EnvironmentOnly = 'environment_only';

    public function editable(): bool
    {
        return $this === self::DatabasePreferred;
    }
}
