<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationRunLogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
