<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Ai\StoreAiPromptTemplateRequest;
use App\Http\Requests\Platform\Ai\StoreAiPromptVersionRequest;
use App\Http\Resources\Ai\AiPromptTemplateResource;
use App\Http\Resources\Ai\AiPromptVersionResource;
use App\Models\AiPromptTemplate;
use App\Models\AiPromptVersion;
use App\Models\AiTask;
use App\Services\Ai\Admin\AiAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform administration of prompt templates.
 *
 * The prompt is the trusted instruction; versions are immutable so an
 * operator always knows exactly which instruction served which requests,
 * and activation is a pointer move rather than an edit.
 */
final class AiPromptTemplateController extends Controller
{
    public function __construct(private readonly AiAdminService $admin) {}

    public function index(): AnonymousResourceCollection
    {
        $templates = AiPromptTemplate::query()
            ->with(['task', 'activeVersion'])
            ->withCount('versions')
            ->orderBy('task_id')
            ->orderBy('key')
            ->paginate(50);

        return AiPromptTemplateResource::collection($templates);
    }

    /**
     * The template with its full version history, newest first.
     */
    public function show(AiPromptTemplate $template): AiPromptTemplateResource
    {
        $template->load(['task', 'activeVersion', 'versions.author']);

        return new AiPromptTemplateResource($template);
    }

    public function store(StoreAiPromptTemplateRequest $request): JsonResponse
    {
        $task = AiTask::query()
            ->where('key', (string) $request->validated('task_key'))
            ->firstOrFail();

        $template = $task->promptTemplates()->create(
            $request->safe()->except('task_key') + ['task_id' => $task->getKey()],
        );

        // A template serves nothing until it has a version; the dashboard
        // creates the first one immediately through the version endpoint.
        return ApiResponse::created('Template created.', new AiPromptTemplateResource($template->load(['task'])));
    }

    /**
     * Append the next version, optionally activating it.
     */
    public function storeVersion(StoreAiPromptVersionRequest $request, AiPromptTemplate $template): JsonResponse
    {
        $version = $this->admin->createPromptVersion(
            $template,
            (string) $request->validated('locale'),
            (string) $request->validated('system_prompt'),
            $request->validated('user_prompt'),
            (array) $request->validated('variables', []),
            $request->user()?->getKey(),
            $request->validated('change_note'),
            (bool) $request->validated('activate', true),
        );

        $version->setRelation('template', $template->refresh());

        return ApiResponse::created('Version created.', new AiPromptVersionResource($version));
    }

    /**
     * Move the template's active pointer to an existing version.
     */
    public function activateVersion(AiPromptTemplate $template, AiPromptVersion $version): JsonResponse
    {
        if ($version->template_id !== $template->getKey()) {
            abort(404);
        }

        $template->forceFill(['active_version_id' => $version->getKey()])->save();

        return ApiResponse::success('Version activated.', new AiPromptVersionResource(
            $version->setRelation('template', $template->refresh()),
        ));
    }
}
