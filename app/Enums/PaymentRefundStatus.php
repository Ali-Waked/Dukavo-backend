<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentRefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }
}
