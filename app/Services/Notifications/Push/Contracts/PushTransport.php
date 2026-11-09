<?php

declare(strict_types=1);

namespace App\Services\Notifications\Push\Contracts;

use App\Enums\NotificationPriority;
use App\Models\NotificationDevice;
use App\Services\Notifications\Push\PushDeliveryException;

/**
 * The seam between the notification engine and a push provider.
 *
 * The engine never imports a Firebase class. Tests replace this binding with a
 * recorder, and a future provider can be swapped without touching delivery
 * state, retries or token cleanup.
 */
interface PushTransport
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, scalar|null>  $data
     * @return string the provider's message id
     *
     * @throws PushDeliveryException
     */
    public function send(
        NotificationDevice $device,
        string $title,
        string $body,
        array $data,
        string $category,
        NotificationPriority $priority,
    ): string;
}
