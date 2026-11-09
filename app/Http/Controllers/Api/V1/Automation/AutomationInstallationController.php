<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Automation;

use App\Enums\AutomationInstallationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\ApplyAutomationTemplateUpdateRequest;
use App\Http\Requests\Automation\UpdateAutomationInstallationRequest;
use App\Http\Resources\AutomationTemplateInstallationResource;
use App\Models\AutomationTemplateInstallation;
use App\Services\Automation\AutomationTemplateInstaller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Merchant installations of platform templates.
 *
 * Template updates are exposed as a preview and a separate confirmed action.
 * Removing an installation soft-deletes the underlying rule, so its run history
 * remains auditable.
 */
final class AutomationInstallationController extends Controller
{
    public function __construct(private readonly AutomationTemplateInstaller $installer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $installations = AutomationTemplateInstallation::query()
            ->with(['template', 'rule', 'store', 'installer'])
            ->when($request->filled('store_id'), fn ($query) => $query->where('store_id', $request->integer('store_id')))
            ->when(
                AutomationInstallationStatus::tryFrom($request->string('status')->toString()) !== null,
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 100))
            ->withQueryString();

        return AutomationTemplateInstallationResource::collection($installations);
    }

    public function show(AutomationTemplateInstallation $installation): AutomationTemplateInstallationResource
    {
        $installation->load(['template', 'rule', 'store', 'installer', 'events.actor']);
        $installation->setAttribute('update_preview', $this->installer->updatePreview($installation));

        return new AutomationTemplateInstallationResource($installation);
    }

    public function update(
        UpdateAutomationInstallationRequest $request,
        AutomationTemplateInstallation $installation,
    ): AutomationTemplateInstallationResource {
        $installation = $this->installer->updateConfiguration(
            $installation,
            $request->validated('configuration'),
            $request->user(),
        );

        return new AutomationTemplateInstallationResource(
            $installation->load(['template', 'rule', 'store', 'installer']),
        );
    }

    public function enable(Request $request, AutomationTemplateInstallation $installation): AutomationTemplateInstallationResource
    {
        return new AutomationTemplateInstallationResource(
            $this->installer->setEnabled($installation, true, $request->user())
                ->load(['template', 'rule', 'store', 'installer']),
        );
    }

    public function disable(Request $request, AutomationTemplateInstallation $installation): AutomationTemplateInstallationResource
    {
        return new AutomationTemplateInstallationResource(
            $this->installer->setEnabled($installation, false, $request->user())
                ->load(['template', 'rule', 'store', 'installer']),
        );
    }

    public function destroy(Request $request, AutomationTemplateInstallation $installation): JsonResponse
    {
        $this->installer->remove($installation, $request->user());

        return ApiResponse::success('Automation template removed.');
    }

    public function preview(AutomationTemplateInstallation $installation): JsonResponse
    {
        $installation->loadMissing('template', 'rule');

        return ApiResponse::success('', $this->installer->updatePreview($installation));
    }

    public function applyUpdate(
        ApplyAutomationTemplateUpdateRequest $request,
        AutomationTemplateInstallation $installation,
    ): AutomationTemplateInstallationResource {
        $installation = $this->installer->applyUpdate(
            $installation,
            $request->user(),
            $request->boolean('force'),
        );

        return new AutomationTemplateInstallationResource(
            $installation->load(['template', 'rule', 'store', 'installer']),
        );
    }
}
