<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Enums\AutomationTaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\UpdateAutomationTaskRequest;
use App\Http\Resources\AutomationTaskResource;
use App\Models\AutomationTask;
use App\Services\Automation\AutomationTaskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The human follow-up queue produced by automations.
 *
 * Completing or dismissing a task records who made the decision; it does not
 * mutate the underlying order, stock row or recovery opportunity.
 */
final class AutomationTaskController extends Controller
{
    public function __construct(private readonly AutomationTaskService $tasks) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = AutomationTask::query()
            ->with(['rule', 'store', 'assignee', 'creator', 'completer'])
            ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $request->user()?->getKey()))
            ->when($request->filled('automation_rule_id'), fn ($query) => $query->where('automation_rule_id', $request->integer('automation_rule_id')))
            ->when(
                AutomationTaskStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when($request->filled('assigned_to'), fn ($query) => $query->where('assigned_to', $request->integer('assigned_to')))
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return AutomationTaskResource::collection($tasks);
    }

    public function show(AutomationTask $automationTask): AutomationTaskResource
    {
        return new AutomationTaskResource($automationTask->load(['rule', 'run', 'store', 'assignee', 'creator', 'completer']));
    }

    public function update(UpdateAutomationTaskRequest $request, AutomationTask $automationTask): AutomationTaskResource
    {
        $status = AutomationTaskStatus::from($request->validated('status'));

        return new AutomationTaskResource(
            $this->tasks->updateStatus($automationTask, $status, $request->user()),
        );
    }
}
