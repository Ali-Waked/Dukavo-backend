<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
