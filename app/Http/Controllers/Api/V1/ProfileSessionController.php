<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileSessionResource;
use App\Services\Profile\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The devices the user is signed in on.
 *
 * Each session is one Sanctum personal access token. Sessions are scoped to the
 * caller through `$user->tokens()`, so the `{token}` route param can never be
 * used to revoke another user's session.
 */
class ProfileSessionController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $sessions = $request->user()
            ->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();

        return ProfileSessionResource::collection($sessions);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $session = $request->user()->tokens()->whereKey($token)->first();

        if ($session === null) {
            abort(404, 'The requested session was not found.');
        }

        $session->delete();

        return ApiResponse::success('Session signed out.');
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $revoked = $this->profiles->revokeOtherTokens($request->user());

        return ApiResponse::success(
            $revoked === 1 ? 'Signed out 1 other session.' : "Signed out {$revoked} other sessions.",
        );
    }
}
