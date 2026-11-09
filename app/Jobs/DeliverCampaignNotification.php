<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationCampaignRecipientStatus;
use App\Enums\NotificationCampaignStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationScope;
use App\Enums\NotificationSeverity;
use App\Events\Notifications\NotificationCreated;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\ChannelAvailability;
use App\Services\Notifications\NotificationCampaignService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivers one campaign to one merchant.
 *
 * The recipient ledger row is created before anything is sent, so a campaign
 * can always account for the person. Preferences are enforced here, server
 * side: a marketing campaign is not sent on a channel the user has not opted
 * into, and a non-marketing service notice still respects channel availability.
 * The notification's dedupe key plus the ledger's unique index make a retried
 * or duplicated job harmless.
 */
final class DeliverCampaignNotification implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $campaignId,
        public readonly int $userId,
    ) {
        $this->onQueue('notifications-campaigns');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('notification-campaigns')];
    }

    public function handle(
        NotificationCampaignService $campaigns,
        ChannelAvailability $availability,
    ): void {
        $campaign = NotificationCampaign::query()->find($this->campaignId);
        $user = User::query()->find($this->userId);

        if (! $campaign instanceof NotificationCampaign || $campaign->status === NotificationCampaignStatus::Cancelled) {
            return;
        }

        $recipient = NotificationCampaignRecipient::query()->firstOrCreate(
            ['campaign_id' => $campaign->getKey(), 'user_id' => $this->userId],
            ['status' => NotificationCampaignRecipientStatus::Pending],
        );

        if (! $user instanceof User) {
            $recipient->forceFill([
                'status' => NotificationCampaignRecipientStatus::Skipped,
                'error' => 'user_missing',
            ])->save();
            $campaign->increment('skipped_count');

            return;
        }

        if (in_array($recipient->status, [
            NotificationCampaignRecipientStatus::Sent,
            NotificationCampaignRecipientStatus::Skipped,
        ], true)) {
            return;
        }

        $tenant = $this->tenantFor($campaign, $user);
        $preferences = $user->notificationPreferences($tenant?->getKey());

        try {
            $channels = $this->channelsFor($campaign, $user, $preferences, $availability, (bool) $tenant);

            if ($channels === []) {
                $recipient->forceFill([
                    'status' => NotificationCampaignRecipientStatus::Skipped,
                    'error' => 'no_channel_allowed',
                ])->save();
                $campaign->increment('skipped_count');

                return;
            }

            $preview = [
                'title' => str_replace(
                    ['{name}', '{workspace}'],
                    [$user->name, $tenant?->name ?? ''],
                    $campaign->title,
                ),
                'body' => str_replace(
                    ['{name}', '{workspace}'],
                    [$user->name, $tenant?->name ?? ''],
                    $campaign->body,
                ),
            ];

            $inApp = in_array('in_app', $channels, true);

            $notification = Notification::query()->firstOrCreate(
                [
                    'notifiable_type' => $user->getMorphClass(),
                    'notifiable_id' => $user->getKey(),
                    'dedupe_key' => 'campaign:'.$campaign->uuid.':user:'.$user->getKey(),
                ],
                [
                    'tenant_id' => $tenant?->getKey(),
                    'scope' => $tenant ? NotificationScope::Tenant : NotificationScope::Platform,
                    'event_key' => 'platform.broadcast',
                    'category' => $campaign->category,
                    'severity' => $campaign->severity ?? NotificationSeverity::Info,
                    'priority' => $campaign->priority ?? NotificationPriority::Normal,
                    'title' => $preview['title'],
                    'body' => $preview['body'],
                    'action_url' => $campaign->action_url,
                    'data' => [
                        'campaign_id' => $campaign->getKey(),
                        'campaign_uuid' => $campaign->uuid,
                    ],
                    'archived_at' => $inApp ? null : now(),
                ],
            );

            $queued = [];

            foreach ($channels as $key) {
                $channel = $this->channelEnum($key);

                if (! $channel instanceof NotificationChannel) {
                    continue;
                }

                $delivery = NotificationDelivery::query()->firstOrCreate(
                    ['notification_id' => $notification->getKey(), 'channel' => $channel->value],
                    [
                        'event_key' => 'platform.broadcast',
                        'recipient_type' => $user->getMorphClass(),
                        'recipient_id' => $user->getKey(),
                        'status' => NotificationDeliveryStatus::Queued,
                        'attempts' => 0,
                        'queued_at' => now(),
                    ],
                );

                if ($delivery->status === NotificationDeliveryStatus::Queued) {
                    $queued[] = [(int) $delivery->getKey(), $channel];
                }
            }

            $recipient->forceFill([
                'notification_id' => $notification->getKey(),
                'status' => $queued === [] ? NotificationCampaignRecipientStatus::Sent : NotificationCampaignRecipientStatus::Queued,
                'queued_at' => now(),
                'sent_at' => $queued === [] ? now() : null,
                'error' => null,
            ])->save();

            $campaign->increment($queued === [] ? 'sent_count' : 'queued_count');

            DB::afterCommit(function () use ($notification, $inApp, $queued): void {
                if ($inApp) {
                    event(new NotificationCreated($notification));
                }

                foreach ($queued as [$deliveryId, $channel]) {
                    match ($channel) {
                        NotificationChannel::Mail => SendNotificationEmail::dispatch($deliveryId),
                        NotificationChannel::Push => SendNotificationPush::dispatch($deliveryId),
                        NotificationChannel::WhatsApp => SendNotificationWhatsApp::dispatch($deliveryId),
                    };
                }
            });
        } catch (Throwable $exception) {
            $recipient->forceFill([
                'status' => NotificationCampaignRecipientStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
                'failed_at' => now(),
            ])->save();

            $campaign->increment('failed_count');

            throw $exception;
        }
    }

    /**
     * @param  array<string, array<string, bool>>  $preferences
     * @return list<string>
     */
    private function channelsFor(
        NotificationCampaign $campaign,
        User $user,
        array $preferences,
        ChannelAvailability $availability,
        bool $hasTenant,
    ): array {
        $allowed = [];

        foreach ((array) $campaign->channels as $key) {
            if (! in_array($key, NotificationCampaignService::CHANNELS, true)) {
                continue;
            }

            if ($key === 'in_app') {
                // In-app is always written, but marketing only appears in the
                // bell for someone who consented to product news.
                if (! $campaign->is_marketing || (bool) ($preferences['in_app']['product_news'] ?? false)) {
                    $allowed[] = 'in_app';
                }

                continue;
            }

            $channel = $this->channelEnum($key);

            if (! $channel instanceof NotificationChannel || ! $availability->isAvailable($channel)) {
                continue;
            }

            if ($channel === NotificationChannel::Mail) {
                if (blank($user->email)) {
                    continue;
                }

                if (config('dukavo.notifications.require_verified_email', true) && $user->email_verified_at === null) {
                    continue;
                }
            }

            if ($channel === NotificationChannel::Push && ! $user->notificationDevices()->active()->exists()) {
                continue;
            }

            $preferenceKey = $channel->preferenceKey();

            if ($campaign->is_marketing && ! (bool) ($preferences[$preferenceKey]['product_news'] ?? false)) {
                continue;
            }

            $allowed[] = $key;
        }

        return array_values(array_unique($allowed));
    }

    private function channelEnum(string $key): ?NotificationChannel
    {
        return match ($key) {
            'mail' => NotificationChannel::Mail,
            'push' => NotificationChannel::Push,
            'whatsapp' => NotificationChannel::WhatsApp,
            default => null,
        };
    }

    private function tenantFor(NotificationCampaign $campaign, User $user): ?Tenant
    {
        $tenantIds = (array) ($campaign->audience['tenant_ids'] ?? []);

        if ($tenantIds !== []) {
            $tenant = $user->tenants()->whereIn('tenants.id', array_map('intval', $tenantIds))->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return $user->tenants()->orderBy('tenants.id')->first();
    }
}
