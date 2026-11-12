<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform\Ai;

use App\Enums\AiErrorCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Ai\AiActivityLogResource;
use App\Models\AiActivityLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The AI activity log: one row per provider attempt.
 *
 * Platform operators read across tenants on purpose — this is the
 * troubleshooting surface for the whole installation. The rows carry
 * metadata only (provider, model, duration, failure category); prompts
 * and answers are never in this table, so this index cannot leak them.
 */
final class AiActivityLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AiActivityLog::query()
            ->when(
                $request->filled('provider'),
                fn ($query) => $query->where('provider', $request->string('provider')->toString()),
            )
            ->when(
                $request->filled('task'),
                fn ($query) => $query->where('task', $request->string('task')->toString()),
            )
            ->when(
                $request->filled('feature'),
                fn ($query) => $query->where('feature', $request->string('feature')->toString()),
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('error_category'),
                fn ($query) => $query->where(
                    'error_category',
                    AiErrorCategory::tryFrom($request->string('error_category')->toString())?->value,
                ),
            )
            ->when(
                $request->filled('correlation_id'),
                fn ($query) => $query->where('correlation_id', $request->string('correlation_id')->toString()),
            )
            ->when(
                $request->filled('tenant_id'),
                fn ($query) => $query->where('tenant_id', $request->integer('tenant_id')),
            )
            ->when(
                $request->filled('user_id'),
                fn ($query) => $query->where('user_id', $request->integer('user_id')),
            )
            ->when($request->boolean('cache_hit'), fn ($query) => $query->where('cache_hit', true))
            ->when(
                $request->filled('from') && $request->filled('until'),
                fn ($query) => $query->whereBetween(
                    'created_at',
                    [$request->date('from')->startOfDay(), $request->date('until')->endOfDay()],
                ),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100))
            ->withQueryString();

        return AiActivityLogResource::collection($logs);
    }

    /**
     * The failure categories, so a filter UI does not hard-code the enum.
     */
    public function categories(): JsonResponse
    {
        return ApiResponse::success('', [
            'categories' => array_map(
                static fn (AiErrorCategory $category): array => [
                    'value' => $category->value,
                    'may_fail_over' => $category->mayFailOver(),
                    'may_retry' => $category->mayRetry(),
                ],
                AiErrorCategory::cases(),
            ),
        ]);
    }
}
