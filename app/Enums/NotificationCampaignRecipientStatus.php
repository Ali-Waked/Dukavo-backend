<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The per-recipient state of a campaign delivery.
 *
 * A recipient row exists as soon as the audience is resolved. `skipped` means
 * the platform deliberately did not send — no marketing consent, no active
 * device, or the channel was disabled — and is not a failure.
 */
enum NotificationCampaignRecipientStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
