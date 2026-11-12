<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Ai\SyncAiTaskRoutesRequest;
use App\Http\Requests\Platform\Ai\UpdateAiTaskRequest;
use App\Http\Resources\Ai\AiTaskResource;
use App\Http\Resources\Ai\AiTaskRouteResource;
use App\Models\AiTask;
use App\Services\Ai\Admin\AiAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Platform administration of task routing.
 *
 * A task's chain decides which providers serve it and in which order the
 * engine fails over. Chains are replaced whole, never edited row by row,
 * so a task is never observed mid-edit.
 */
final class AiTaskController extends Controller
{
    public function __construct(private readonly AiAdminService $admin) {}

    public function index(): AnonymousResourceCollection
    {
        $tasks = AiTask::query()
            ->with(['routes.provider'])
            ->withCount('promptTemplates')
            ->orderBy('key')
            ->paginate(100);

        return AiTaskResource::collection($tasks);
    }

    /**
     * Create the task rows from the engine's enum. Idempotent and safe to
     * run any time; it is how a fresh installation starts routing.
     */
    public function seed(): JsonResponse
    {
        $created = $this->admin->seedTasks();

        return ApiResponse::success(sprintf('%d tasks created.', $created), [
            'created' => $created,
            'tasks' => AiTask::query()->orderBy('key')->pluck('key')->all(),
        ]);
    }

    public function show(AiTask $task): AiTaskResource
    {
        $task->load(['routes.provider']);

        return new AiTaskResource($task);
    }

    public function update(UpdateAiTaskRequest $request, AiTask $task): JsonResponse
    {
        $task->fill($request->safe()->all())->save();

        return ApiResponse::success('Task updated.', new AiTaskResource($task->refresh()->load('routes.provider')));
    }

    /**
     * Replace the task's platform routing chain.
     */
    public function syncRoutes(SyncAiTaskRoutesRequest $request, AiTask $task): JsonResponse
    {
        $routes = $this->admin->syncRoutes($task, $request->routes());

        $task->setRelation('routes', collect($routes));

        return ApiResponse::success('Routing chain updated.', [
            'task' => new AiTaskResource($task),
            'routes' => AiTaskRouteResource::collection(collect($routes))->resolve(),
        ]);
    }
}
