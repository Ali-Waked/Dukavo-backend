<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationCampaignStatus;
use App\Models\NotificationCampaign;
use App\Services\Notifications\NotificationCampaignService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Resolves a campaign's audience and fans it out in bounded batches.
 *
 * The audience is streamed with `chunkById`, so a campaign to every merchant
 * never loads every user into memory. Each chunk becomes one queue batch; a
 * large campaign therefore occupies a predictable amount of memory and can be
 * monitored or retried batch by batch.
 */
final class PublishNotificationCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $campaignId)
    {
        $this->onQueue('notifications-campaigns');
    }

    public function handle(NotificationCampaignService $campaigns): void
    {
        $campaign = NotificationCampaign::query()->find($this->campaignId);

        if (! $campaign instanceof NotificationCampaign
            || $campaign->status !== NotificationCampaignStatus::Publishing) {
            return;
        }

        $query = $campaigns->audienceQuery($campaign->audience);
        $total = (clone $query)->count();

        $campaign->forceFill(['total_recipients' => $total])->save();

        if ($total === 0) {
            $campaigns->markCompleted($campaign);

            return;
        }

        $chunkSize = max(1, (int) config('dukavo.notifications.broadcast.chunk_size', 250));

        $query->select('id')->chunkById($chunkSize, function ($users) use ($campaign): void {
            $current = NotificationCampaign::query()->find($campaign->getKey());

            // A campaign cancelled while chunks are being queued must stop
            // immediately; already-queued recipient jobs re-check this too.
            if (! $current instanceof NotificationCampaign
                || $current->status === NotificationCampaignStatus::Cancelled) {
                return;
            }

            $jobs = $users
                ->map(fn ($user) => new DeliverCampaignNotification((int) $campaign->getKey(), (int) $user->getKey()))
                ->all();

            if ($jobs === []) {
                return;
            }

            Bus::batch($jobs)
                ->name('notification-campaign:'.$campaign->getKey())
                ->onQueue('notifications-campaigns')
                ->allowFailures()
                ->dispatch();
        });

        $campaign->refresh();

        if ($campaign->status === NotificationCampaignStatus::Publishing) {
            $campaign->forceFill([
                'status' => NotificationCampaignStatus::Published,
            ])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $campaign = NotificationCampaign::query()->find($this->campaignId);

        if ($campaign instanceof NotificationCampaign && ! $campaign->isTerminal()) {
            $campaign->forceFill(['status' => NotificationCampaignStatus::Failed])->save();
        }
    }
}
