<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\AutomationTemplateStatus;
use App\Enums\AutomationTrigger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\StoreAutomationTemplateRequest;
use App\Http\Requests\Automation\UpdateAutomationTemplateRequest;
use App\Http\Resources\AutomationTemplateResource;
use App\Http\Resources\AutomationTemplateVersionResource;
use App\Models\AutomationTemplate;
use App\Services\Automation\AutomationDefinition;
use App\Services\Automation\AutomationTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Platform-owned automation template administration.
 *
 * Templates are global blueprints: they are authored here, never inside a
 * merchant workspace, and publishing a version does not rewrite an installed
 * rule. Merchant installations remain tenant-scoped and are offered updates
 * through the merchant-facing installation endpoints.
 */
final class AutomationTemplateController extends Controller
{
    public function __construct(private readonly AutomationTemplateService $templates) {}

    /**
     * The vocabulary a platform author may use.
     *
     * The merchant meta endpoint lives inside a tenant context, which a
     * platform operator does not have. Reusing the same registry keeps the
     * authoring form and the execution engine on one catalogue.
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::success('', [
            'triggers' => AutomationDefinition::triggers(),
            'operators' => AutomationDefinition::operators(),
            'actions' => AutomationDefinition::actions(),
            'task_priorities' => AutomationDefinition::taskPriorities(),
            'categories' => AutomationDefinition::templateCategories(),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = AutomationTemplate::query()
            ->withCount('installations')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when(
                AutomationTemplateStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->toString()))
            ->when(
                AutomationTrigger::tryFrom($request->string('trigger')->toString()) !== null,
                fn ($query) => $query->where('trigger', $request->string('trigger')->toString()),
            )
            ->ordered()
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return AutomationTemplateResource::collection($templates);
    }

    public function store(StoreAutomationTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated(), $request->user());

        return ApiResponse::created(
            'Automation template created as a draft.',
            new AutomationTemplateResource($template->load('creator')),
        );
    }

    public function show(AutomationTemplate $automationTemplate): AutomationTemplateResource
    {
        return new AutomationTemplateResource(
            $automationTemplate
                ->load(['creator', 'updater', 'versions.creator'])
                ->loadCount('installations'),
        );
    }

    public function update(UpdateAutomationTemplateRequest $request, AutomationTemplate $automationTemplate): AutomationTemplateResource
    {
        $template = $this->templates->update($automationTemplate, $request->validated(), $request->user());

        return new AutomationTemplateResource($template->load(['creator', 'updater']));
    }

    public function destroy(AutomationTemplate $automationTemplate): Response
    {
        $this->templates->delete($automationTemplate);

        return response()->noContent();
    }

    public function publish(Request $request, AutomationTemplate $automationTemplate): AutomationTemplateResource
    {
        return new AutomationTemplateResource(
            $this->templates->publish($automationTemplate, $request->user())->load('updater'),
        );
    }

    public function archive(Request $request, AutomationTemplate $automationTemplate): AutomationTemplateResource
    {
        return new AutomationTemplateResource(
            $this->templates->archive($automationTemplate, $request->user())->load('updater'),
        );
    }

    public function versions(AutomationTemplate $automationTemplate): AnonymousResourceCollection
    {
        return AutomationTemplateVersionResource::collection(
            $automationTemplate->versions()->with('creator')->paginate(50),
        );
    }
}
