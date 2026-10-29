<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The closed set of things an automation rule may do.
 *
 * There is deliberately no "run code", "call a URL" or raw data mutation here.
 * Higher-risk effects are proposed through the existing AI action approval
 * workflow, so an automation cannot bypass a human decision.
 */
enum AutomationActionType: string
{
    case NotifyTeam = 'notify_team';
    case CreateTask = 'create_task';
    case ProposeAiAction = 'propose_ai_action';

    public function label(): string
    {
        return match ($this) {
            self::NotifyTeam => 'Notify the team',
            self::CreateTask => 'Create an internal task',
            self::ProposeAiAction => 'Propose an action for approval',
        };
    }
}
