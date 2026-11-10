<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTransactionType: string
{
    case Charge = 'charge';
    case Refund = 'refund';
}
