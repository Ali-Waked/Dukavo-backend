<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Enums\AiActionClassification;
use App\Exceptions\BusinessRuleViolation;
use App\Models\AiAction;
use App\Models\Tenant;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The single place that decides what a classification requires.
 *
 * Executors declare a classification; this policy turns it into approval,
 * confirmation and expiry rules, applies any installation overrides, and checks
 * optional per-tenant plan restrictions. Keeping it here means an executor can
 * never quietly opt out of review.
 */
final class AiActionPolicy
{
    public function classify(AiActionExecutor $executor): AiActionClassification
    {
        return $executor->classification();
    }

    /**
     * @return array{approval_required: bool, confirmation_required: bool, expires_after_minutes: int}
     */
    public function requirementsFor(AiActionClassification $classification): array
    {
        $defaults = $classification->defaultRequirements();
        $override = config('ai.governance.classifications.'.$classification->value);

        if (! is_array($override)) {
            return $defaults;
        }

        // Approval may never be removed from an action that changes data, no
        // matter what configuration says.
        $approval = $classification->executable()
            ? true
            : (bool) ($override['approval_required'] ?? $defaults['approval_required']);

        return [
            'approval_required' => $approval,
            'confirmation_required' => (bool) ($override['confirmation_required'] ?? $defaults['confirmation_required']),
            'expires_after_minutes' => max(1, (int) ($override['expires_after_minutes'] ?? $defaults['expires_after_minutes'])),
        ];
    }

    public function requiresConfirmation(AiActionClassification $classification): bool
    {
        return $this->requirementsFor($classification)['confirmation_required'];
    }

    public function expiresAt(AiActionClassification $classification, ?DateTimeInterface $from = null): CarbonImmutable
    {
        $minutes = $this->requirementsFor($classification)['expires_after_minutes'];
        $start = $from instanceof DateTimeInterface
            ? CarbonImmutable::instance($from)
            : CarbonImmutable::now();

        return $start->addMinutes($minutes);
    }

    public function hasExpired(AiAction $action, ?DateTimeInterface $at = null): bool
    {
        if ($action->expires_at === null) {
            return false;
        }

        $moment = $at instanceof DateTimeInterface
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::now();

        return $action->expires_at->lessThanOrEqualTo($moment);
    }

    /**
     * Optional plan restriction.
     *
     * Dukavo does not bind a billing plan to a tenant yet, so the absence of the
     * setting means "no plan restriction". When a deployment does attach one it
     * writes `settings.ai_governance.allowed_classifications` (or
     * `settings.ai_governance.actions_enabled = false`) and this check enforces
     * it server-side, before a proposal is even stored.
     */
    public function assertPlanAllows(?Tenant $tenant, AiActionClassification $classification): void
    {
        if (! $tenant instanceof Tenant) {
            return;
        }

        $settings = $tenant->settings['ai_governance'] ?? null;

        if (! is_array($settings)) {
            return;
        }

        if (($settings['actions_enabled'] ?? true) === false) {
            throw BusinessRuleViolation::because(
                'AI actions are not enabled for this workspace plan.',
                ['classification' => $classification->value],
                'ai_action_plan_restricted',
            );
        }

        $allowed = $settings['allowed_classifications'] ?? null;

        if (is_array($allowed) && $allowed !== [] && ! in_array($classification->value, $allowed, true)) {
            throw BusinessRuleViolation::because(
                'This action classification is not available on the workspace plan.',
                ['classification' => $classification->value],
                'ai_action_plan_restricted',
            );
        }

        $maximum = $settings['max_classification'] ?? null;

        if (is_string($maximum)) {
            $max = AiActionClassification::tryFrom($maximum);

            if ($max instanceof AiActionClassification && $classification->rank() > $max->rank()) {
                throw BusinessRuleViolation::because(
                    'This action classification is not available on the workspace plan.',
                    ['classification' => $classification->value],
                    'ai_action_plan_restricted',
                );
            }
        }
    }
}
