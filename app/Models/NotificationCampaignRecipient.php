<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationCampaignRecipientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merchant's place in a platform campaign.
 *
 * The row is created when the audience is resolved, before any channel is
 * attempted, so a campaign can always account for every person it selected.
 */
#[Fillable([
    'campaign_id', 'user_id', 'notification_id', 'status', 'error',
    'queued_at', 'sent_at', 'failed_at',
])]
class NotificationCampaignRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'status' => NotificationCampaignRecipientStatus::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NotificationCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
