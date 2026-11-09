<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationRunResource;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only execution history.
 *
 * Runs explain both matches and skips, which is what lets a merchant debug a
 * rule that is active but apparently doing nothing. Retrying is a write
 * operation: it re-evaluates the failed actions and must be explicitly requested.
 */
final class AutomationRunController extends Controller
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = AutomationRun::query()
            ->with('rule')
            ->when($request->filled('automation_rule_id'), fn ($query) => $query->where('automation_rule_id', $request->integer('automation_rule_id')))
            ->when(
                AutomationRunStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                AutomationTrigger::tryFrom($request->string('trigger')->toString()) !== null,
                fn ($query) => $query->where('trigger', $request->string('trigger')->toString()),
            )
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return AutomationRunResource::collection($runs);
    }

    public function show(AutomationRun $automationRun): AutomationRunResource
    {
        return new AutomationRunResource($automationRun->load(['rule', 'logs']));
    }

    public function retry(AutomationRun $automationRun): AutomationRunResource
    {
        $run = $this->engine->retry($automationRun);

        return new AutomationRunResource($run->load(['rule', 'logs']));
    }
}
