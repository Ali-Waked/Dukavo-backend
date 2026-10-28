<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\RecoveryOpportunityStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\RecoveryAssistantRequest;
use App\Http\Requests\Recovery\AssignRecoveryRequest;
use App\Http\Requests\Recovery\ContactRecoveryRequest;
use App\Http\Requests\Recovery\DismissRecoveryRequest;
use App\Http\Requests\Recovery\DoNotContactRecoveryRequest;
use App\Http\Requests\Recovery\IndexRecoveryOpportunitiesRequest;
use App\Http\Requests\Recovery\SnoozeRecoveryRequest;
use App\Http\Resources\RecoveryOpportunityResource;
use App\Models\Customer;
use App\Models\RecoveryOpportunity;
use App\Models\User;
use App\Services\Ai\Features\RecoveryAssistant;
use App\Services\Recovery\RecoveryService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * The lost-sale recovery workspace.
 *
 * Reads expose the underlying cart, order and conversation so an agent can see
 * why an opportunity exists. Writes are explicit and human-driven; nothing here
 * sends a message without a reviewed body or template.
 */
final class RecoveryOpportunityController extends Controller
{
    public function __construct(
        private readonly RecoveryService $recovery,
        private readonly TenantContext $tenants,
    ) {}

    public function index(IndexRecoveryOpportunitiesRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $search = trim((string) ($filters['search'] ?? ''));

        $opportunities = RecoveryOpportunity::query()
            ->with(['customer', 'assignee', 'conversation', 'cart.items', 'order'])
            ->when($filters['status'] ?? null, function ($query, string $status): void {
                if ($status === 'open') {
                    $query->open();
                } else {
                    $query->withStatus($status);
                }
            })
            ->when($filters['reason'] ?? null, fn ($query, string $reason) => $query->where('reason', $reason))
            ->when($filters['assigned_to'] ?? null, fn ($query, $userId) => $query->where('assigned_to', (int) $userId))
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $request->user()->getKey()))
            ->when($request->boolean('unassigned'), fn ($query) => $query->whereNull('assigned_to'))
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.addcslashes($search, '\\%_').'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->whereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term))
                        ->orWhereHas('conversation', fn ($conversation) => $conversation
                            ->where('display_name', 'like', $term)
                            ->orWhere('phone', 'like', $term));
                });
            })
            ->orderByRaw("CASE WHEN status IN ('eligible', 'contacted') THEN 0 WHEN status = 'snoozed' THEN 1 ELSE 2 END")
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return RecoveryOpportunityResource::collection($opportunities);
    }

    /**
     * The funnel and money at risk, grouped by currency.
     *
     * Recovered revenue is joined to the order and excludes cancellations. An
     * opportunity's estimated value is a cart snapshot, never counted as
     * revenue.
     */
    public function summary(): JsonResponse
    {
        $statusCounts = RecoveryOpportunity::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = static fn (string $status): int => (int) ($statusCounts[$status] ?? 0);

        $openValue = RecoveryOpportunity::query()
            ->open()
            ->whereNotNull('currency')
            ->selectRaw('currency, SUM(estimated_value) as total, COUNT(*) as aggregate')
            ->groupBy('currency')
            ->get()
            ->map(static fn ($row): array => [
                'currency' => (string) $row->currency,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        $recovered = RecoveryOpportunity::query()
            ->withStatus(RecoveryOpportunityStatus::Recovered)
            ->join('orders', 'orders.id', '=', 'recovery_opportunities.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('orders.currency as currency, SUM(orders.grand_total) as total, COUNT(*) as aggregate')
            ->groupBy('orders.currency')
            ->get()
            ->map(static fn ($row): array => [
                'currency' => (string) $row->currency,
                'total' => round((float) $row->total, 2),
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        return ApiResponse::success('Recovery summary.', [
            'counts' => [
                'total' => (int) $statusCounts->sum(),
                'open' => $count('eligible') + $count('contacted') + $count('snoozed'),
                'eligible' => $count('eligible'),
                'contacted' => RecoveryOpportunity::query()->where('contact_attempts', '>', 0)->count(),
                'responded' => RecoveryOpportunity::query()->whereNotNull('responded_at')->count(),
                'recovered' => $count('recovered'),
                'snoozed' => $count('snoozed'),
                'dismissed' => $count('dismissed'),
                'expired' => $count('expired'),
            ],
            'open_value' => $openValue,
            'recovered_revenue' => $recovered,
        ]);
    }

    public function show(RecoveryOpportunity $recoveryOpportunity): RecoveryOpportunityResource
    {
        return new RecoveryOpportunityResource(
            $recoveryOpportunity->load([
                'customer',
                'assignee',
                'conversation',
                'cart.items',
                'order',
                'events.actor',
            ]),
        );
    }

    public function draft(
        RecoveryAssistantRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
        RecoveryAssistant $assistant,
    ): JsonResponse {
        $result = $assistant->draft(
            $recoveryOpportunity,
            $request->user(),
            $request->validated('instruction'),
        );

        return ApiResponse::success('Recovery draft ready for review.', $result);
    }

    public function contact(
        ContactRecoveryRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
    ): JsonResponse {
        $payload = $request->validated();
        $payload['mode'] ??= 'text';

        $opportunity = $this->recovery->contact($recoveryOpportunity, $request->user(), $payload);

        return ApiResponse::success('Recovery message sent.', new RecoveryOpportunityResource($opportunity));
    }

    public function assign(
        AssignRecoveryRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
    ): JsonResponse {
        $userId = $request->validated('user_id');
        $assignee = null;

        if ($userId !== null) {
            $tenant = $this->tenants->required();

            $assignee = User::query()
                ->whereKey((int) $userId)
                ->where('status', 'active')
                ->whereHas('tenants', fn ($query) => $query->whereKey($tenant->getKey()))
                ->first();

            if (! $assignee instanceof User || ! $assignee->hasPermissionTo('recovery.view', $tenant)) {
                throw BusinessRuleViolation::because(
                    'The selected team member cannot access the recovery workspace.',
                    ['user_id' => $userId],
                    'invalid_assignee',
                );
            }
        }

        $opportunity = $this->recovery->assign($recoveryOpportunity, $assignee, $request->user());

        return ApiResponse::success('Recovery opportunity assigned.', new RecoveryOpportunityResource($opportunity));
    }

    public function snooze(
        SnoozeRecoveryRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
    ): JsonResponse {
        $opportunity = $this->recovery->snooze(
            $recoveryOpportunity,
            Carbon::parse((string) $request->validated('until')),
            $request->user(),
        );

        return ApiResponse::success('Recovery opportunity snoozed.', new RecoveryOpportunityResource($opportunity));
    }

    public function dismiss(
        DismissRecoveryRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
    ): JsonResponse {
        $opportunity = $this->recovery->dismiss(
            $recoveryOpportunity,
            $request->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success('Recovery opportunity dismissed.', new RecoveryOpportunityResource($opportunity));
    }

    /**
     * Record consent at the customer level and close every open opportunity for
     * that customer, not only the row the agent happened to open.
     */
    public function doNotContact(
        DoNotContactRecoveryRequest $request,
        RecoveryOpportunity $recoveryOpportunity,
    ): JsonResponse {
        $recoveryOpportunity->loadMissing('customer');
        $customer = $recoveryOpportunity->customer;

        if (! $customer instanceof Customer) {
            throw BusinessRuleViolation::because('This opportunity has no customer to update.');
        }

        $affected = $this->recovery->doNotContact(
            $customer,
            $request->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success('Customer marked as do not contact.', [
            'customer_id' => $customer->getKey(),
            'dismissed_opportunities' => $affected,
        ]);
    }
}
