<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a platform announcement.
 *
 * `scheduled` is not `published`: a scheduled campaign has a row and a time but
 * no recipient ledger yet. Publishing moves it through `publishing` to
 * `published` (queued) and finally `completed`. `cancelled` is terminal and can
 * be reached from `scheduled` or `publishing`.
 */
enum NotificationCampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }
}
