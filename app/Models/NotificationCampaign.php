<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationCampaignStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A platform announcement and its delivery progress.
 *
 * Merchants never see this model. It is only reachable through the platform
 * administration API, and it deliberately has no tenant: a campaign spans
 * workspaces and each recipient's notification carries that recipient's own
 * tenant when they belong to exactly one.
 */
#[Fillable([
    'uuid', 'created_by', 'title', 'body', 'category', 'severity', 'priority',
    'action_url', 'channels', 'audience', 'is_marketing', 'status', 'scheduled_at', 'published_at',
    'cancelled_at', 'completed_at', 'total_recipients', 'queued_count', 'sent_count',
    'failed_count', 'skipped_count', 'idempotency_key',
])]
class NotificationCampaign extends Model
{
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience' => 'array',
            'is_marketing' => 'boolean',
            'status' => NotificationCampaignStatus::class,
            'severity' => NotificationSeverity::class,
            'priority' => NotificationPriority::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_recipients' => 'integer',
            'queued_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NotificationCampaign $campaign): void {
            if (blank($campaign->uuid)) {
                $campaign->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<NotificationCampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationCampaignRecipient::class, 'campaign_id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Campaigns the scheduler must publish now.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where('status', NotificationCampaignStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}
