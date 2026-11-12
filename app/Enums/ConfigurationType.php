<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shape of a stored configuration value.
 *
 * Validation, casting and the form control the clients render all come from
 * this type, so a value cannot be accepted as one type and interpreted as
 * another.
 */
enum ConfigurationType: string
{
    case String = 'string';
    case Text = 'text';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Email = 'email';
    case Url = 'url';
    case Secret = 'secret';
    case Select = 'select';
    case MultiSelect = 'multi_select';

    public function isSecret(): bool
    {
        return $this === self::Secret;
    }

    /**
     * Whether the value is a list stored as JSON.
     */
    public function isList(): bool
    {
        return $this === self::MultiSelect;
    }
}
