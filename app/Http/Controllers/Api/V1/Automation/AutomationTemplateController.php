<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\InstallAutomationTemplateRequest;
use App\Http\Resources\AutomationTemplateInstallationResource;
use App\Http\Resources\AutomationTemplateResource;
use App\Models\AutomationTemplate;
use App\Models\AutomationTemplateInstallation;
use App\Services\Automation\AutomationTemplateInstaller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The merchant-facing template library.
 *
 * Only published templates from the platform appear here. Templates are global
 * blueprints; the installation row (and the executable rule) is always scoped
 * to the merchant's tenant and, optionally, one store.
 */
final class AutomationTemplateController extends Controller
{
    public function __construct(private readonly AutomationTemplateInstaller $installer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $storeId = $request->filled('store_id') ? $request->integer('store_id') : null;
        $scopeKey = AutomationTemplateInstallation::scopeKey($storeId);

        $templates = AutomationTemplate::query()
            ->published()
            ->ordered()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('trigger'), fn ($query) => $query->where('trigger', $request->string('trigger')->toString()))
            ->paginate(min($request->integer('per_page', 15), 100))
            ->withQueryString();

        $installations = AutomationTemplateInstallation::query()
            ->where('scope_key', $scopeKey)
            ->whereIn('automation_template_id', $templates->pluck('id'))
            ->get()
            ->keyBy('automation_template_id');

        foreach ($templates as $template) {
            $template->setAttribute('installation_state', $installations->get($template->getKey()));
        }

        return AutomationTemplateResource::collection($templates);
    }

    public function show(AutomationTemplate $automationTemplate): AutomationTemplateResource
    {
        abort_unless($automationTemplate->isInstallable(), 404);

        return new AutomationTemplateResource(
            $automationTemplate->load(['installations' => fn ($query) => $query
                ->with(['store', 'rule'])
                ->orderByDesc('id')]),
        );
    }

    public function install(
        InstallAutomationTemplateRequest $request,
        AutomationTemplate $automationTemplate,
    ): JsonResponse {
        $validated = $request->validated();
        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;

        $installation = $this->installer->install(
            $automationTemplate,
            $storeId,
            $validated['configuration'] ?? [],
            $request->user(),
        );

        return ApiResponse::created(
            'Automation template installed. Review it and enable it when you are ready.',
            new AutomationTemplateInstallationResource($installation),
        );
    }
}
