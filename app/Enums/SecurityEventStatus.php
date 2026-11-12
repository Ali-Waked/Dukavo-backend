<?php

declare(strict_types=1);

namespace App\Enums;

enum SecurityEventStatus: string
{
    case Success = 'success';
    case Failure = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failure => 'Failed',
        };
    }
}
