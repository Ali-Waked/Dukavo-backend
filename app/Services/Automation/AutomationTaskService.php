<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\AutomationTaskStatus;
use App\Models\AutomationTask;
use App\Models\User;

/**
 * Advances an internal task's lifecycle.
 *
 * The task is a human work item; changing its status never touches the order,
 * stock row or opportunity that caused it.
 */
final class AutomationTaskService
{
    public function updateStatus(AutomationTask $task, AutomationTaskStatus $status, User $actor): AutomationTask
    {
        if ($task->status === $status) {
            return $task;
        }

        $terminal = $status->isTerminal();

        $task->forceFill([
            'status' => $status,
            'completed_by' => $terminal ? $actor->getKey() : null,
            'completed_at' => $terminal ? now() : null,
        ])->save();

        return $task->load(['rule', 'run', 'store', 'assignee', 'creator', 'completer']);
    }
}
