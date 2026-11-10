<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentWebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
