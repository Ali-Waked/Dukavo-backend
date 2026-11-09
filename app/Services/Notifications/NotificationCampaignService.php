<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\AuditAction;
use App\Enums\NotificationCampaignStatus;
use App\Enums\NotificationSeverity;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Jobs\PublishNotificationCampaign;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform's announcement lifecycle.
 *
 * Only Dukavo platform staff reach this service, and only through the platform
 * API. The service owns every status transition so a campaign cannot be
 * published twice, edited after sending, or cancelled after completion. All
 * campaign writes are audited against the acting operator.
 */
final class NotificationCampaignService
{
    public const CHANNELS = ['in_app', 'push', 'mail'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): NotificationCampaign
    {
        if (filled($data['idempotency_key'] ?? null)) {
            $existing = NotificationCampaign::query()
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing instanceof NotificationCampaign) {
                return $existing;
            }
        }

        $campaign = NotificationCampaign::query()->create([
            'created_by' => $actor->getKey(),
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'] ?? 'announcements',
            'severity' => NotificationSeverity::tryFrom((string) ($data['severity'] ?? 'info')) ?? NotificationSeverity::Info,
            'priority' => $data['priority'] ?? 'normal',
            'action_url' => $data['action_url'] ?? null,
            'channels' => $this->channels($data['channels'] ?? ['in_app']),
            'audience' => $this->audience($data['audience'] ?? ['type' => 'all']),
            'is_marketing' => (bool) ($data['is_marketing'] ?? false),
            'status' => NotificationCampaignStatus::Draft,
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        $this->audit->record(AuditAction::NotificationCampaignCreated, $campaign, [
            'new' => $campaign->only(['title', 'channels', 'audience', 'status']),
        ], $actor);

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(NotificationCampaign $campaign, User $actor, array $data): NotificationCampaign
    {
        if (! $campaign->isEditable()) {
            throw BusinessRuleViolation::because('Only draft or scheduled campaigns can be edited.', errorCode: 'campaign_not_editable');
        }

        $before = $campaign->only(['title', 'body', 'channels', 'audience', 'scheduled_at', 'is_marketing']);

        $campaign->fill([
            'title' => $data['title'] ?? $campaign->title,
            'body' => $data['body'] ?? $campaign->body,
            'category' => $data['category'] ?? $campaign->category,
            'severity' => $data['severity'] ?? $campaign->severity,
            'priority' => $data['priority'] ?? $campaign->priority,
            'action_url' => $data['action_url'] ?? $campaign->action_url,
            'channels' => isset($data['channels']) ? $this->channels($data['channels']) : $campaign->channels,
            'audience' => isset($data['audience']) ? $this->audience($data['audience']) : $campaign->audience,
            'is_marketing' => $data['is_marketing'] ?? $campaign->is_marketing,
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : $campaign->scheduled_at,
        ]);

        // A scheduled campaign whose time moved stays scheduled; one with no
        // time returns to draft so the scheduler does not pick it up early.
        $campaign->status = $campaign->scheduled_at !== null
            ? NotificationCampaignStatus::Scheduled
            : NotificationCampaignStatus::Draft;

        $campaign->save();

        $this->audit->record(AuditAction::NotificationCampaignUpdated, $campaign, [
            'old' => $before,
            'new' => $campaign->only(['title', 'body', 'channels', 'audience', 'scheduled_at', 'is_marketing']),
        ], $actor);

        return $campaign;
    }

    public function schedule(NotificationCampaign $campaign, User $actor, Carbon $when): NotificationCampaign
    {
        if (! $campaign->isEditable()) {
            throw BusinessRuleViolation::because('Only draft or scheduled campaigns can be scheduled.', errorCode: 'campaign_not_schedulable');
        }

        if ($when->isPast()) {
            throw BusinessRuleViolation::because('A campaign cannot be scheduled in the past.', errorCode: 'campaign_schedule_past');
        }

        $campaign->forceFill([
            'status' => NotificationCampaignStatus::Scheduled,
            'scheduled_at' => $when,
        ])->save();

        $this->audit->record(AuditAction::NotificationCampaignUpdated, $campaign, [
            'new' => ['status' => 'scheduled', 'scheduled_at' => $when->toIso8601String()],
        ], $actor);

        return $campaign;
    }

    /**
     * Queue a campaign for delivery.
     *
     * The status flips to `publishing` before the job is dispatched, so a
     * double-clicked publish finds a campaign already in flight rather than
     * creating a second recipient ledger.
     */
    public function publish(NotificationCampaign $campaign, ?User $actor = null): NotificationCampaign
    {
        if (in_array($campaign->status, [
            NotificationCampaignStatus::Publishing,
            NotificationCampaignStatus::Published,
            NotificationCampaignStatus::Completed,
        ], true)) {
            return $campaign;
        }

        if ($campaign->status === NotificationCampaignStatus::Cancelled) {
            throw BusinessRuleViolation::because('A cancelled campaign cannot be published.', errorCode: 'campaign_cancelled');
        }

        $campaign->forceFill([
            'status' => NotificationCampaignStatus::Publishing,
            'published_at' => now(),
        ])->save();

        DB::afterCommit(fn () => PublishNotificationCampaign::dispatch($campaign->getKey()));

        $this->audit->record(AuditAction::NotificationCampaignPublished, $campaign, [
            'new' => ['status' => 'publishing', 'published_at' => $campaign->published_at?->toIso8601String()],
        ], $actor);

        return $campaign;
    }

    public function cancel(NotificationCampaign $campaign, User $actor): NotificationCampaign
    {
        if ($campaign->isTerminal()) {
            return $campaign;
        }

        $campaign->forceFill([
            'status' => NotificationCampaignStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::NotificationCampaignCancelled, $campaign, [], $actor);

        return $campaign;
    }

    public function markCompleted(NotificationCampaign $campaign): void
    {
        if ($campaign->status === NotificationCampaignStatus::Published) {
            $campaign->forceFill([
                'status' => NotificationCampaignStatus::Completed,
                'completed_at' => now(),
            ])->save();
        }
    }

    /**
     * The users a campaign is allowed to reach.
     *
     * Platform staff are excluded even when the audience says "all": an
     * announcement to merchants must never leak into Dukavo's own operator
     * inbox as if they were a customer.
     *
     * @param  array<string, mixed>  $audience
     * @return Builder<User>
     */
    public function audienceQuery(array $audience): Builder
    {
        $type = (string) ($audience['type'] ?? 'all');

        $query = User::query()
            ->where('status', UserStatus::Active)
            ->whereHas('tenants')
            ->whereDoesntHave('roleAssignments', fn (Builder $roles) => $roles->whereNull('tenant_id'));

        if ($type === 'tenants') {
            $ids = array_map('intval', (array) ($audience['tenant_ids'] ?? []));
            $query->whereHas('tenants', fn (Builder $tenants) => $tenants->whereIn('tenants.id', $ids));
        }

        if ($type === 'users') {
            $ids = array_map('intval', (array) ($audience['user_ids'] ?? []));
            $query->whereIn('id', $ids);
        }

        $excluded = array_map('intval', (array) ($audience['exclude_user_ids'] ?? []));

        if ($excluded !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query->orderBy('id');
    }

    /**
     * @return array{title: string, body: string}
     */
    public function preview(NotificationCampaign $campaign, ?User $user = null): array
    {
        $name = $user?->name ?? 'Sara';
        $workspace = $user?->tenants()->first()?->name ?? 'Your workspace';

        return [
            'title' => str_replace(['{name}', '{workspace}'], [$name, $workspace], $campaign->title),
            'body' => str_replace(['{name}', '{workspace}'], [$name, $workspace], $campaign->body),
        ];
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function channels(array $channels): array
    {
        $clean = array_values(array_unique(array_intersect($channels, self::CHANNELS)));

        if ($clean === []) {
            throw BusinessRuleViolation::because('A campaign needs at least one channel.', errorCode: 'campaign_channel_missing');
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<string, mixed>
     */
    private function audience(array $audience): array
    {
        $type = (string) ($audience['type'] ?? 'all');

        if (! in_array($type, ['all', 'tenants', 'users'], true)) {
            throw BusinessRuleViolation::because('Unknown campaign audience.', errorCode: 'campaign_audience_invalid');
        }

        return [
            'type' => $type,
            'tenant_ids' => array_values(array_map('intval', (array) ($audience['tenant_ids'] ?? []))),
            'user_ids' => array_values(array_map('intval', (array) ($audience['user_ids'] ?? []))),
            'exclude_user_ids' => array_values(array_map('intval', (array) ($audience['exclude_user_ids'] ?? []))),
        ];
    }
}
