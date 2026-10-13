<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Services\Profile\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own account.
 *
 * Every action here acts on `$request->user()`, never on a route-bound user, so
 * there is no id in the path to tamper with: a user can only ever edit
 * themselves. Editing *other* people is the tenant/platform user controllers'
 * job and requires `users.manage`.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user());
    }

    public function update(UpdateProfileRequest $request): ProfileResource
    {
        return new ProfileResource($this->profiles->update($request->user(), $request->validated()));
    }

    public function storeAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->profiles->storeAvatar($request->user(), $request->file('avatar'));

        return ApiResponse::success('Avatar updated.', new ProfileResource($user));
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $this->profiles->deleteAvatar($request->user());

        return ApiResponse::success('Avatar removed.', new ProfileResource($user));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $this->profiles->updatePassword(
            $request->user(),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        );

        return ApiResponse::success('Password updated. Other devices have been signed out.');
    }
}
