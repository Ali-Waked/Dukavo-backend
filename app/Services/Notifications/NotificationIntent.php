<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationSeverity;

/**
 * What an emitter wants to notify about.
 *
 * It names an event by its catalogue key and hands over the data needed to
 * render that event; it does *not* decide scope, recipients, channels or
 * preferences, which belong to the catalogue and the dispatcher. A `dedupeKey`
 * identifies the logical event (for example the order it concerns) so a retried
 * or doubled business event collapses to a single notification per recipient.
 */
final readonly class NotificationIntent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public string $eventKey,
        public ?int $tenantId = null,
        public string $dedupeKey = '',
        public array $data = [],
        public ?string $actionUrl = null,
        public ?int $actorId = null,
        public ?NotificationSeverity $severity = null,
    ) {}

    /**
     * The idempotency key for one recipient: the logical event plus the user it
     * concerns, so two identical events cannot produce two notifications.
     */
    public function eventKeyFor(int $userId): string
    {
        $base = $this->dedupeKey !== '' ? $this->dedupeKey : $this->eventKey;

        return $base.':user:'.$userId;
    }
}
