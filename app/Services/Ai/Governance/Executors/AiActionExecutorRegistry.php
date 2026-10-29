<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Executors;

use App\Enums\AiActionClassification;
use App\Exceptions\BusinessRuleViolation;
use App\Services\Ai\Governance\Contracts\AiActionExecutor;

/**
 * The executors this installation offers, indexed by action type.
 *
 * The set is closed on purpose: an action type that has no executor cannot be
 * approved and cannot run, so a proposal can never name a capability the
 * application does not actually have.
 */
final class AiActionExecutorRegistry
{
    /**
     * @var array<string, AiActionExecutor>
     */
    private array $executors = [];

    /**
     * @param  iterable<AiActionExecutor>  $executors
     */
    public function __construct(iterable $executors = [])
    {
        foreach ($executors as $executor) {
            $this->executors[$executor->actionType()] = $executor;
        }
    }

    public function has(string $actionType): bool
    {
        return isset($this->executors[$actionType]);
    }

    public function get(string $actionType): AiActionExecutor
    {
        return $this->executors[$actionType] ?? throw BusinessRuleViolation::because(
            sprintf('There is no executor for the "%s" action.', $actionType),
        );
    }

    /**
     * @return list<string>
     */
    public function actionTypes(): array
    {
        return array_keys($this->executors);
    }

    /**
     * The permission each action type requires, for the UI to describe.
     *
     * @return array<string, string>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach ($this->executors as $actionType => $executor) {
            $permissions[$actionType] = $executor->requiredPermission();
        }

        return $permissions;
    }

    /**
     * @return array<string, AiActionClassification>
     */
    public function classifications(): array
    {
        $classifications = [];

        foreach ($this->executors as $actionType => $executor) {
            $classifications[$actionType] = $executor->classification();
        }

        return $classifications;
    }

    /**
     * Every executor as the UI needs to describe it.
     *
     * @return list<array{action_type: string, permission: string, classification: string, classification_label: string}>
     */
    public function describe(): array
    {
        $described = [];

        foreach ($this->executors as $actionType => $executor) {
            $classification = $executor->classification();

            $described[] = [
                'action_type' => $actionType,
                'permission' => $executor->requiredPermission(),
                'classification' => $classification->value,
                'classification_label' => $classification->label(),
            ];
        }

        return $described;
    }
}
