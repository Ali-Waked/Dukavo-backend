<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Ai;

use App\Enums\AiActionClassification;
use App\Enums\AiActionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ApproveAiActionRequest;
use App\Http\Requests\Ai\ProposeAiActionRequest;
use App\Http\Requests\Ai\RejectAiActionRequest;
use App\Http\Resources\AiActionResource;
use App\Models\AiAction;
use App\Services\Ai\Governance\AiActionPolicy;
use App\Services\Ai\Governance\AiActionService;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The human side of AI governance.
 *
 * A model can propose an action; it cannot approve or run one. This controller
 * exposes the review queue and the transitions, while {@see AiActionService}
 * owns the permissions, expiry, confirmation and idempotency rules.
 */
final class AiActionController extends Controller
{
    public function __construct(
        private readonly AiActionService $actions,
        private readonly AiActionExecutorRegistry $executors,
        private readonly AiActionPolicy $policy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actions = AiAction::query()
            ->with(['proposer', 'approver', 'executor'])
            ->when($request->filled('status') && AiActionStatus::tryFrom($request->string('status')->toString()) instanceof AiActionStatus, function ($query) use ($request): void {
                $query->where('status', $request->string('status')->toString());
            })
            ->when($request->filled('action_type'), fn ($query) => $query->where('action_type', $request->string('action_type')->toString()))
            ->when($request->filled('use_case'), fn ($query) => $query->where('use_case', $request->string('use_case')->toString()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100))
            ->withQueryString();

        return AiActionResource::collection($actions);
    }

    public function show(AiAction $action): AiActionResource
    {
        return new AiActionResource($action->load(['proposer', 'approver', 'executor']));
    }

    /**
     * The capabilities this installation can execute, with the permission and
     * classification each one requires. The UI uses this to avoid offering an
     * approval the person could not legally run.
     */
    public function types(): JsonResponse
    {
        $types = $this->executors->describe();

        foreach ($types as &$type) {
            $classification = AiActionClassification::from($type['classification']);
            $type['requirements'] = $this->policy->requirementsFor($classification);
        }

        return ApiResponse::success('AI action types.', $types);
    }

    public function store(ProposeAiActionRequest $request): JsonResponse
    {
        $action = $this->actions->propose(
            $request->validated('use_case'),
            $request->validated('action_type'),
            (array) $request->validated('payload'),
            $request->user(),
            $request->validated('summary'),
            $request->validated('idempotency_key'),
        );

        return ApiResponse::created('AI action proposed.', new AiActionResource($action->load('proposer')));
    }

    public function submit(AiAction $action): JsonResponse
    {
        $action = $this->actions->submit($action, request()->user());

        return ApiResponse::success('AI action submitted for approval.', new AiActionResource($action->load('proposer')));
    }

    public function approve(ApproveAiActionRequest $request, AiAction $action): JsonResponse
    {
        $action = $this->actions->approve(
            $action,
            $request->user(),
            (bool) $request->validated('confirmed', false),
        );

        return ApiResponse::success('AI action approved.', new AiActionResource($action->load(['proposer', 'approver', 'executor'])));
    }

    public function reject(RejectAiActionRequest $request, AiAction $action): JsonResponse
    {
        $action = $this->actions->reject($action, $request->user(), $request->validated('reason'));

        return ApiResponse::success('AI action rejected.', new AiActionResource($action->load(['proposer', 'approver'])));
    }

    public function execute(AiAction $action): JsonResponse
    {
        $action = $this->actions->execute($action, request()->user());

        return ApiResponse::success('AI action executed.', new AiActionResource($action->load(['proposer', 'approver', 'executor'])));
    }
}
