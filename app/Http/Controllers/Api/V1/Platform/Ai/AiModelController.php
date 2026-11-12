<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Ai\StoreAiModelRequest;
use App\Http\Requests\Platform\Ai\UpdateAiModelRequest;
use App\Http\Resources\Ai\AiModelResource;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Platform administration of the models a provider offers.
 *
 * Capabilities declared here are the same enum the router matches against,
 * so a model that is declared without a capability a task requires simply
 * never receives that task.
 */
final class AiModelController extends Controller
{
    /**
     * @return AnonymousResourceCollection<AiModelResource>
     */
    public function index(AiProvider $provider): AnonymousResourceCollection
    {
        $models = $provider->models()
            ->orderByDesc('is_default')
            ->orderBy('key')
            ->paginate(100);

        return AiModelResource::collection($models);
    }

    public function store(StoreAiModelRequest $request, AiProvider $provider): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $provider): AiModel {
            $model = $provider->models()->create($request->safe()->all());

            if ($model->is_default) {
                $this->demoteOtherDefaults($provider, $model);
            }

            return $model;
        });

        return ApiResponse::created('Model declared.', new AiModelResource($model));
    }

    public function update(UpdateAiModelRequest $request, AiModel $model): JsonResponse
    {
        $model = DB::transaction(function () use ($request, $model): AiModel {
            $model->fill($request->safe()->all())->save();

            if ($model->is_default) {
                $this->demoteOtherDefaults($model->provider, $model);
            }

            return $model->refresh();
        });

        return ApiResponse::success('Model updated.', new AiModelResource($model));
    }

    public function destroy(AiModel $model): JsonResponse
    {
        $model->delete();

        return ApiResponse::noContent('Model removed.');
    }

    /**
     * One default model per provider: routing falls back to it when a hop
     * does not name a model, so two defaults would be ambiguous.
     */
    private function demoteOtherDefaults(AiProvider $provider, AiModel $keep): void
    {
        $provider->models()
            ->whereKeyNot($keep->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
