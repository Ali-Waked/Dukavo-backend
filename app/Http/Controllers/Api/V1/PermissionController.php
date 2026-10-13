<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PermissionRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The permission catalogue.
 *
 * Read-only, and available to anyone who may view roles, because the role editor
 * needs to render every permission a merchant *could* grant — not only the ones
 * the current user happens to hold.
 *
 * The response is grouped by module rather than paginated: the whole catalogue
 * is small, static, and cached in the permission cache after first read.
 */
class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $entries = PermissionRegistry::all();

        return response()->json([
            'success' => true,
            'data' => [
                'modules' => collect(PermissionRegistry::groupedByModule())
                    ->map(fn (array $slugs, string $module): array => [
                        'module' => $module,
                        'permissions' => collect($entries)
                            ->filter(fn (array $permission): bool => in_array($permission['slug'], $slugs, true))
                            ->values(),
                    ])
                    ->values(),
                'slugs' => PermissionRegistry::slugs(),
            ],
        ]);
    }
}
