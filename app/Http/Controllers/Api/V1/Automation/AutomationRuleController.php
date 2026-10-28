<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreAutomationRuleRequest;
use App\Http\Requests\Automation\UpdateAutomationRuleRequest;
use App\Http\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationRuleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Merchant automation rules.
 *
 * Reads are separated from writes at the route layer: `automation.view` can
 * inspect what a rule does and why it ran, while only `automation.manage` may
 * create, change, pause or delete one.
 */
final class AutomationRuleController extends Controller
{
    public function __construct(private readonly AutomationRuleService $rules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rules = AutomationRule::query()
            ->with(['store', 'creator', 'latestRun'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when(
                AutomationRuleStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                AutomationTrigger::tryFrom($request->string('trigger')->toString()) !== null,
                fn ($query) => $query->where('trigger', $request->string('trigger')->toString()),
            )
            ->when($request->filled('store_id'), fn ($query) => $query->where('store_id', $request->integer('store_id')))
            ->orderByDesc('updated_at')
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        return AutomationRuleResource::collection($rules);
    }

    public function store(StoreAutomationRuleRequest $request): JsonResponse
    {
        $rule = $this->rules->create($request->validated(), $request->user());

        return ApiResponse::created('Automation rule created.', new AutomationRuleResource($rule->load('latestRun')));
    }

    public function show(AutomationRule $automationRule): AutomationRuleResource
    {
        return new AutomationRuleResource($automationRule->load(['store', 'creator', 'latestRun']));
    }

    public function update(UpdateAutomationRuleRequest $request, AutomationRule $automationRule): AutomationRuleResource
    {
        $rule = $this->rules->update($automationRule, $request->validated(), $request->user());

        return new AutomationRuleResource($rule->load('latestRun'));
    }

    public function destroy(AutomationRule $automationRule): Response
    {
        $this->rules->delete($automationRule);

        return response()->noContent();
    }
}
