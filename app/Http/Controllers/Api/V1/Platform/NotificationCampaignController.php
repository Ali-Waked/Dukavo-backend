<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\NotificationCampaignRecipientStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\NotificationAudienceRequest;
use App\Http\Requests\Platform\PreviewNotificationCampaignRequest;
use App\Http\Requests\Platform\ScheduleNotificationCampaignRequest;
use App\Http\Requests\Platform\StoreNotificationCampaignRequest;
use App\Http\Requests\Platform\UpdateNotificationCampaignRequest;
use App\Http\Resources\NotificationCampaignRecipientResource;
use App\Http\Resources\NotificationCampaignResource;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\Notifications\NotificationCampaignService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Platform announcement administration.
 *
 * Every route is registered behind the `platform` middleware *and* a
 * `notifications.view` or `notifications.manage` permission, so neither a
 * merchant token nor a platform operator without the grant can reach it. A
 * campaign is Dukavo's own communication, never a merchant's, which is why
 * there is no tenant header and no tenant scope on these queries.
 *
 * Lifecycle enforcement lives in the service; this controller only translates
 * HTTP into service calls. That keeps list, preview, publish and the scheduler
 * sharing exactly one set of state rules.
 */
class NotificationCampaignController extends Controller
{
    public function __construct(private readonly NotificationCampaignService $campaigns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $campaigns = NotificationCampaign::query()
            ->with('creator')
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->string('status')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('title', 'like', '%'.$request->string('search')->toString().'%')
                ->orWhere('body', 'like', '%'.$request->string('search')->toString().'%')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return NotificationCampaignResource::collection($campaigns);
    }

    public function store(StoreNotificationCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->user(), $request->validated());

        return ApiResponse::created(
            'Campaign created.',
            new NotificationCampaignResource($campaign->load('creator')),
        );
    }

    public function show(NotificationCampaign $campaign): NotificationCampaignResource
    {
        return new NotificationCampaignResource($campaign->load('creator'));
    }

    public function update(UpdateNotificationCampaignRequest $request, NotificationCampaign $campaign): NotificationCampaignResource
    {
        return new NotificationCampaignResource(
            $this->campaigns->update($campaign, $request->user(), $request->validated())->load('creator'),
        );
    }

    public function destroy(NotificationCampaign $campaign): Response
    {
        if (! $campaign->isEditable()) {
            throw BusinessRuleViolation::because(
                'Only draft or scheduled campaigns can be deleted. Cancel a running campaign instead.',
                errorCode: 'campaign_not_deletable',
            );
        }

        $campaign->delete();

        return response()->noContent();
    }

    public function schedule(ScheduleNotificationCampaignRequest $request, NotificationCampaign $campaign): NotificationCampaignResource
    {
        $campaign = $this->campaigns->schedule(
            $campaign,
            $request->user(),
            Carbon::parse((string) $request->validated('scheduled_at')),
        );

        return new NotificationCampaignResource($campaign->load('creator'));
    }

    public function publish(Request $request, NotificationCampaign $campaign): NotificationCampaignResource
    {
        return new NotificationCampaignResource(
            $this->campaigns->publish($campaign, $request->user())->load('creator'),
        );
    }

    public function cancel(Request $request, NotificationCampaign $campaign): NotificationCampaignResource
    {
        return new NotificationCampaignResource(
            $this->campaigns->cancel($campaign, $request->user())->load('creator'),
        );
    }

    /**
     * Render the copy against a real merchant, without creating anything.
     */
    public function preview(PreviewNotificationCampaignRequest $request): JsonResponse
    {
        $campaign = new NotificationCampaign([
            'title' => (string) $request->validated('title'),
            'body' => (string) $request->validated('body'),
        ]);

        $user = $request->filled('user_id')
            ? User::query()->find((int) $request->validated('user_id'))
            : null;

        return ApiResponse::success('', $this->campaigns->preview($campaign, $user));
    }

    /**
     * How many merchants the given audience would reach right now.
     */
    public function audienceCount(NotificationAudienceRequest $request): JsonResponse
    {
        $audience = $request->audience();

        return ApiResponse::success('', [
            'count' => $this->campaigns->audienceQuery($audience)->count(),
        ]);
    }

    /**
     * The per-recipient ledger, filterable by status so failures are
     * inspectable without downloading the whole campaign.
     */
    public function recipients(Request $request, NotificationCampaign $campaign): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(NotificationCampaignRecipientStatus::class)],
        ]);

        $recipients = $campaign->recipients()
            ->with('user')
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->string('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 25), 100))
            ->withQueryString();

        return NotificationCampaignRecipientResource::collection($recipients);
    }

    /**
     * Delivery progress, broken down by recipient status.
     *
     * The campaign row already carries aggregate counters; this endpoint adds
     * the live per-status breakdown and the most recent failures, which is what
     * an operator needs to decide whether to retry or cancel.
     */
    public function stats(NotificationCampaign $campaign): JsonResponse
    {
        $byStatus = $campaign->recipients()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $failures = $campaign->recipients()
            ->with('user')
            ->where('status', NotificationCampaignRecipientStatus::Failed)
            ->latest('failed_at')
            ->limit(10)
            ->get();

        return ApiResponse::success('', [
            'status' => $campaign->status?->value,
            'counts' => [
                'total' => $campaign->total_recipients,
                'queued' => $campaign->queued_count,
                'sent' => $campaign->sent_count,
                'failed' => $campaign->failed_count,
                'skipped' => $campaign->skipped_count,
                'by_status' => $byStatus,
            ],
            'recent_failures' => NotificationCampaignRecipientResource::collection($failures)->resolve(),
        ]);
    }
}
