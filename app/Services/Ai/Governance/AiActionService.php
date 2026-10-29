<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Enums\AiActionClassification;
use App\Enums\AiActionStatus;
use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AiAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use App\Services\Ai\Governance\Executors\AiActionExecutorRegistry;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use App\Support\Redaction\SensitiveDataRedactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The lifecycle of an AI-proposed business action.
 *
 * This service is the only thing that may move an action from one state to
 * another, and it enforces five rules:
 *
 *  1. A proposal is not an action. `propose()` writes a draft; it changes no
 *     business data and it resolves the payload against real records, so what
 *     is stored is what the application vouches for rather than what the model
 *     said.
 *  2. Approval is a human decision, gated on `ai.approve` at the route and the
 *     executor's own permission here. Current permissions are re-checked at
 *     execution, so revoking access between approval and execution stops it.
 *  3. A decision expires. A stale approval must never be applied to a changed
 *     world.
 *  4. Execution is idempotent. The row is locked, the status is checked, and an
 *     already-executed action is a no-op — a retry, a double click or a
 *     duplicated queue entry cannot apply the same change twice.
 *  5. Every transition is audited.
 */
final class AiActionService
{
    public function __construct(
        private readonly AiActionExecutorRegistry $executors,
        private readonly AiActionPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
        private readonly SensitiveDataRedactor $redactor,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Record a proposal.
     *
     * The payload is resolved here, before the row is written, so a proposal
     * that names records this tenant does not have is refused outright rather
     * than stored for someone to approve later.
     *
     * @param  array<string, mixed>  $payload
     */
    public function propose(
        string $useCase,
        string $actionType,
        array $payload,
        User $actor,
        ?string $summary = null,
        ?string $idempotencyKey = null,
    ): AiAction {
        $tenant = $this->tenantFor($actor);
        $executor = $this->executors->get($actionType);
        $classification = $this->policy->classify($executor);

        if (! $classification->executable()) {
            throw BusinessRuleViolation::because(
                'That classification is informational and cannot be stored as an executable action.',
            );
        }

        $this->assertPermission($actor, $executor->requiredPermission(), $tenant);
        $this->policy->assertPlanAllows($tenant, $classification);

        $key = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? mb_substr(trim($idempotencyKey), 0, 120)
            : null;

        if ($key !== null) {
            $existing = AiAction::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof AiAction) {
                if ($existing->action_type !== $actionType) {
                    throw BusinessRuleViolation::because(
                        'That idempotency key already belongs to a different action.',
                        ['idempotency_key' => $key],
                        'ai_action_idempotency_conflict',
                    );
                }

                return $existing;
            }
        }

        $resolved = $executor->resolve($payload);

        try {
            $action = AiAction::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $actor->getKey(),
                'use_case' => mb_substr($useCase, 0, 60),
                'action_type' => $actionType,
                'classification' => $classification,
                'status' => AiActionStatus::Draft,
                'summary' => $summary !== null && trim($summary) !== ''
                    ? mb_substr(trim($summary), 0, 255)
                    : null,
                // What the model said is retained for diagnosis, but secrets
                // are removed before the row is written.
                'payload' => $this->redactor->redact($payload),
                'resolved_payload' => $resolved,
                'idempotency_key' => $key,
                'expires_at' => $this->policy->expiresAt($classification),
            ]);
        } catch (QueryException $exception) {
            // A concurrent proposal with the same key won the insert race.
            if ($key !== null) {
                $existing = AiAction::query()->where('idempotency_key', $key)->first();

                if ($existing instanceof AiAction && $existing->action_type === $actionType) {
                    return $existing;
                }
            }

            throw $exception;
        }

        $this->audit->record(
            AuditAction::AiActionProposed,
            $action,
            ['new' => [
                'use_case' => $action->use_case,
                'action_type' => $actionType,
                'classification' => $classification->value,
                'summary' => $action->summary,
            ]],
            $actor,
        );

        return $action;
    }

    /**
     * Offer a draft for approval.
     */
    public function submit(AiAction $action, User $actor): AiAction
    {
        $tenant = $this->tenantFor($actor);
        $executor = $this->executors->get($action->action_type);

        $this->assertPermission($actor, $executor->requiredPermission(), $tenant);
        $this->policy->assertPlanAllows($tenant, $this->classificationOf($action, $executor));

        $submitted = $this->locked($action, function (AiAction $locked) use ($actor, $executor): ?AiAction {
            $classification = $this->classificationOf($locked, $executor);

            if ($this->expireIfStale($locked, $actor)) {
                return null;
            }

            return $this->applyTransition($locked, AiActionStatus::PendingApproval, $actor, AuditAction::AiActionSubmitted, [
                'submitted_at' => now(),
                // The reviewer gets the full window from the moment it reaches
                // them, not from when the draft was first written.
                'expires_at' => $this->policy->expiresAt($classification),
            ]);
        });

        $submitted ?? throw BusinessRuleViolation::because('This proposal has expired. Ask for a fresh proposal.');

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::TENANT_AI_ACTION_AWAITING_REVIEW,
            tenantId: (int) $tenant->getKey(),
            dedupeKey: 'tenant.ai_action.awaiting_review:'.$submitted->getKey(),
            data: [
                'summary' => $submitted->summary ?: $submitted->action_type,
            ],
            actionUrl: '/ai/approvals',
            actorId: (int) $actor->getKey(),
        ));

        return $submitted;
    }

    /**
     * Authorise a proposal.
     *
     * `$confirmed` is the explicit second acknowledgement required for
     * reversible and high-risk actions. It is not inferred from the click that
     * submitted the request.
     */
    public function approve(AiAction $action, User $actor, bool $confirmed = false): AiAction
    {
        $tenant = $this->tenantFor($actor);
        $executor = $this->executors->get($action->action_type);
        $classification = $this->classificationOf($action, $executor);

        $this->assertPermission($actor, $executor->requiredPermission(), $tenant);
        $this->policy->assertPlanAllows($tenant, $classification);

        if (
            (bool) config('ai.governance.require_separate_approver', false)
            && $action->user_id !== null
            && (int) $action->user_id === (int) $actor->getKey()
        ) {
            throw BusinessRuleViolation::because('A proposal must be approved by someone other than the person who made it.');
        }

        if ($this->policy->requiresConfirmation($classification) && ! $confirmed) {
            throw BusinessRuleViolation::because(
                'This action needs an explicit confirmation before it can be approved.',
                ['confirmation_required' => true],
                'ai_action_confirmation_required',
            );
        }

        $approved = $this->locked($action, function (AiAction $locked) use ($actor, $classification, $confirmed): ?AiAction {
            if ($this->expireIfStale($locked, $actor)) {
                return null;
            }

            return $this->applyTransition($locked, AiActionStatus::Approved, $actor, AuditAction::AiActionApproved, [
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'confirmed_by' => $confirmed ? $actor->getKey() : null,
                'confirmed_at' => $confirmed ? now() : null,
                'expires_at' => $this->policy->expiresAt($classification),
            ]);
        });

        return $approved ?? throw BusinessRuleViolation::because('This action has expired. Ask for a fresh proposal.');
    }

    /**
     * Refuse a proposal.
     */
    public function reject(AiAction $action, User $actor, ?string $reason = null): AiAction
    {
        $tenant = $this->tenantFor($actor);
        $executor = $this->executors->get($action->action_type);

        $this->assertPermission($actor, $executor->requiredPermission(), $tenant);

        $rejected = $this->locked($action, function (AiAction $locked) use ($actor, $reason): ?AiAction {
            if ($this->expireIfStale($locked, $actor)) {
                return null;
            }

            return $this->applyTransition($locked, AiActionStatus::Rejected, $actor, AuditAction::AiActionRejected, [
                'rejection_reason' => $reason !== null && trim($reason) !== ''
                    ? mb_substr(trim($reason), 0, 1000)
                    : null,
                'rejected_at' => now(),
            ]);
        });

        return $rejected ?? throw BusinessRuleViolation::because('This action has already expired.');
    }

    /**
     * Run an approved action.
     *
     * The row is locked for the duration, so two concurrent attempts cannot
     * both see `approved` and both apply the change. An action that has
     * already run returns unchanged.
     */
    public function execute(AiAction $action, User $actor): AiAction
    {
        $tenant = $this->tenantFor($actor);
        $executor = $this->executors->get($action->action_type);
        $classification = $this->classificationOf($action, $executor);

        // Authorisation is checked against the *current* permission set, not
        // what was true when the action was approved.
        $this->assertPermission($actor, $executor->requiredPermission(), $tenant);
        $this->policy->assertPlanAllows($tenant, $classification);

        try {
            $result = DB::transaction(function () use ($action, $actor, $executor): ?AiAction {
                $locked = AiAction::query()->lockForUpdate()->findOrFail($action->getKey());

                $this->assertSameTenant($locked);

                if ($locked->status === AiActionStatus::Executed) {
                    // Already applied. Repeating it must not repeat its effect.
                    return $locked;
                }

                if ($this->expireIfStale($locked, $actor)) {
                    return null;
                }

                if (! $locked->status->canTransitionTo(AiActionStatus::Executed)) {
                    throw BusinessRuleViolation::because(sprintf(
                        'An action in the "%s" state cannot be executed.',
                        $locked->status->value,
                    ));
                }

                $payload = $executor->revalidate(is_array($locked->resolved_payload) ? $locked->resolved_payload : []);

                $result = $executor->execute(
                    $payload,
                    $actor,
                    'ai-action:'.$locked->getKey(),
                );

                $locked->forceFill([
                    'status' => AiActionStatus::Executed,
                    'resolved_payload' => $payload,
                    'result' => $this->redactor->redact($result),
                    'executed_at' => now(),
                    'executed_by' => $actor->getKey(),
                    'error' => null,
                ])->save();

                $this->audit->record(
                    AuditAction::AiActionExecuted,
                    $locked,
                    ['new' => ['result' => $this->redactor->redact($result)]],
                    $actor,
                );

                return $locked;
            });
        } catch (Throwable $exception) {
            // The failed status is written in a *new* transaction: the original
            // one has rolled back its business effect, and a status update
            // inside it would roll back too.
            $this->recordFailure($action->getKey(), $actor, $exception);

            throw $exception;
        }

        if ($result === null) {
            throw BusinessRuleViolation::because('This approval has expired. Ask for a fresh proposal.');
        }

        return $result;
    }

    /**
     * Expire one action if its approval window has passed.
     */
    public function expire(AiAction $action, ?User $actor = null): bool
    {
        return $this->locked($action, fn (AiAction $locked): bool => $this->expireIfStale($locked, $actor));
    }

    /**
     * Expire every stale action across every tenant. Intended for a schedule.
     */
    public function expireStale(int $limit = 200): int
    {
        $tenantIds = AiAction::query()
            ->withoutGlobalScopes()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', [AiActionStatus::Executed->value, AiActionStatus::Rejected->value, AiActionStatus::Expired->value])
            ->distinct()
            ->limit($limit)
            ->pluck('tenant_id')
            ->filter()
            ->values();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::query()->find((int) $tenantId);

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $expired += (int) $this->tenants->run($tenant, function () use ($limit): int {
                $actions = AiAction::query()
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->whereNotIn('status', [AiActionStatus::Executed->value, AiActionStatus::Rejected->value, AiActionStatus::Expired->value])
                    ->orderBy('id')
                    ->limit($limit)
                    ->get();

                $count = 0;

                foreach ($actions as $action) {
                    if ($this->expire($action)) {
                        $count++;
                    }
                }

                return $count;
            });
        }

        return $expired;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyTransition(
        AiAction $action,
        AiActionStatus $next,
        ?User $actor,
        AuditAction $auditAction,
        array $attributes = [],
    ): AiAction {
        if (! $action->status->canTransitionTo($next)) {
            throw BusinessRuleViolation::because(sprintf(
                'An action in the "%s" state cannot become "%s".',
                $action->status->value,
                $next->value,
            ));
        }

        $from = $action->status;

        $action->forceFill($attributes + ['status' => $next])->save();

        $this->audit->record(
            $auditAction,
            $action,
            ['old' => ['status' => $from->value], 'new' => ['status' => $next->value]],
            $actor,
        );

        return $action;
    }

    /**
     * Mark a stale action expired and report whether it did so.
     */
    private function expireIfStale(AiAction $action, ?User $actor = null): bool
    {
        if (! $this->policy->hasExpired($action)) {
            return false;
        }

        if (! $action->status->canTransitionTo(AiActionStatus::Expired)) {
            return $action->status === AiActionStatus::Expired;
        }

        $this->applyTransition($action, AiActionStatus::Expired, $actor, AuditAction::AiActionExpired, [
            'error' => 'The approval window expired before execution.',
        ]);

        return true;
    }

    private function recordFailure(int $actionId, User $actor, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($actionId, $actor, $exception): void {
                $action = AiAction::query()->lockForUpdate()->find($actionId);

                if (! $action instanceof AiAction || $action->status !== AiActionStatus::Approved) {
                    return;
                }

                $message = mb_substr($this->redactor->redactString($exception->getMessage()), 0, 1000);

                $action->forceFill([
                    'status' => AiActionStatus::Failed,
                    'error' => $message,
                ])->save();

                $this->audit->record(
                    AuditAction::AiActionFailed,
                    $action,
                    ['new' => ['error' => $message]],
                    $actor,
                );
            });
        } catch (Throwable $auditFailure) {
            report($auditFailure);
        }
    }

    /**
     * Run a callback while holding a row lock on the action.
     *
     * @template TReturn
     *
     * @param  callable(AiAction): TReturn  $callback
     * @return TReturn
     */
    private function locked(AiAction $action, callable $callback): mixed
    {
        return DB::transaction(function () use ($action, $callback): mixed {
            $locked = AiAction::query()->lockForUpdate()->findOrFail($action->getKey());

            $this->assertSameTenant($locked);

            return $callback($locked);
        });
    }

    private function tenantFor(User $actor): Tenant
    {
        $tenant = $this->tenants->required();

        $isPlatformOperator = $actor->isSuperAdmin()
            || $actor->roleAssignments()->whereNull('tenant_id')->whereHas('role', fn ($query) => $query->where('slug', 'dukavo-admin'))->exists();

        $hasTenantRole = $actor->roleAssignments()->where('tenant_id', $tenant->getKey())->exists();

        if (! $isPlatformOperator && ! $hasTenantRole) {
            throw BusinessRuleViolation::because(
                'You do not have access to this workspace.',
                ['tenant_id' => $tenant->getKey()],
                'ai_action_forbidden',
            );
        }

        if (! $isPlatformOperator && ! $tenant->isActive()) {
            throw BusinessRuleViolation::because('This workspace is not active.');
        }

        return $tenant;
    }

    private function assertSameTenant(AiAction $action): void
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null || (int) $action->tenant_id !== $tenantId) {
            throw BusinessRuleViolation::because('This action belongs to another workspace.');
        }
    }

    private function classificationOf(AiAction $action, AiActionExecutor $executor): AiActionClassification
    {
        return $action->classification instanceof AiActionClassification
            ? $action->classification
            : $this->policy->classify($executor);
    }

    private function assertPermission(User $actor, string $permission, Tenant $tenant): void
    {
        if (! $actor->hasPermissionTo($permission, $tenant)) {
            throw BusinessRuleViolation::because(
                sprintf('You do not have the "%s" permission.', $permission),
                ['permission' => $permission],
                'ai_action_forbidden',
            );
        }
    }
}
