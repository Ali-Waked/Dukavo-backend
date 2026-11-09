<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Enums\NotificationCampaignStatus;
use App\Models\NotificationCampaign;
use App\Services\Notifications\NotificationCampaignService;
use Illuminate\Console\Command;

/**
 * Publishes due campaigns and closes finished ones.
 *
 * A scheduled campaign is only a row and a time; something has to notice that
 * the time has arrived. This command is that clock. It also marks a published
 * campaign complete once its recipient ledger has no work left, so the admin
 * screen can distinguish "still sending" from "finished".
 */
class PublishDueCampaigns extends Command
{
    protected $signature = 'notifications:campaigns {--limit=50 : Maximum campaigns to publish per run}';

    protected $description = 'Publish scheduled notification campaigns and complete finished ones';

    public function handle(NotificationCampaignService $campaigns): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $published = 0;

        NotificationCampaign::query()
            ->due()
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get()
            ->each(function (NotificationCampaign $campaign) use ($campaigns, &$published): void {
                // The service flips to `publishing` first, so a second scheduler
                // tick cannot publish the same campaign twice.
                $campaigns->publish($campaign);
                $published++;
            });

        $completed = $this->completeFinished($campaigns);

        $this->info(sprintf('Published %d due campaign(s); completed %d finished campaign(s).', $published, $completed));

        return self::SUCCESS;
    }

    private function completeFinished(NotificationCampaignService $campaigns): int
    {
        $completed = 0;

        NotificationCampaign::query()
            ->where('status', NotificationCampaignStatus::Published)
            ->whereRaw('queued_count + sent_count + failed_count + skipped_count >= total_recipients')
            ->each(function (NotificationCampaign $campaign) use ($campaigns, &$completed): void {
                $campaigns->markCompleted($campaign);
                $completed++;
            });

        return $completed;
    }
}
