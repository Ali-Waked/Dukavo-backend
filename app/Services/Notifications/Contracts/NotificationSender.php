<?php

declare(strict_types=1);

namespace App\Services\Notifications\Contracts;

use App\Enums\NotificationChannel;
use App\Models\NotificationDelivery;

/**
 * Boundary to one outbound transport.
 *
 * A sender's single responsibility is to attempt a delivery and record what
 * actually happened on the row: `sent` only when the provider accepted it,
 * `skipped` when the channel deliberately did not send, and never a claim it
 * cannot evidence. Implementations throw on a transport failure; the job that
 * invokes them turns that into a failed delivery and a retry.
 */
interface NotificationSender
{
    public function channel(): NotificationChannel;

    public function send(NotificationDelivery $delivery): void;
}
