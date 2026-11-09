<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\RegisterNotificationDeviceRequest;
use App\Http\Resources\NotificationDeviceResource;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The push installations owned by the signed-in account.
 *
 * Every action is scoped to `$request->user()`. A device id from another
 * account resolves to a 404, never a 403, so the endpoint cannot be used to
 * discover whether a token exists. Registration is an upsert: the same phone
 * calling twice (boot, token refresh, re-login) updates one row rather than
 * accumulating duplicates that would each receive the same push.
 */
class NotificationDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->notificationDevices()
            ->active()
            ->latest('last_seen_at')
            ->get();

        return ApiResponse::success('', NotificationDeviceResource::collection($devices)->resolve());
    }

    public function store(RegisterNotificationDeviceRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = (string) $request->validated('token');
        $hash = NotificationDevice::hashToken($token);

        $device = NotificationDevice::query()->where('token_hash', $hash)->first();

        if ($device instanceof NotificationDevice) {
            // A phone signed into another account must stop receiving the old
            // account's notifications before the new token is trusted.
            $device->forceFill([
                'user_id' => $user->getKey(),
                'device_id' => $request->validated('device_id'),
                'platform' => $request->validated('platform'),
                'device_name' => $request->validated('device_name'),
                'app_version' => $request->validated('app_version'),
                'locale' => $request->validated('locale'),
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();
        } else {
            $device = NotificationDevice::query()->create([
                'user_id' => $user->getKey(),
                'device_id' => $request->validated('device_id'),
                'platform' => $request->validated('platform'),
                'token' => $token,
                'token_hash' => $hash,
                'device_name' => $request->validated('device_name'),
                'app_version' => $request->validated('app_version'),
                'locale' => $request->validated('locale'),
                'last_seen_at' => now(),
            ]);
        }

        $this->revokeRotatedTokens($user, $device);

        return ApiResponse::success('Device registered.', new NotificationDeviceResource($device->fresh()));
    }

    public function update(Request $request, NotificationDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);

        $validated = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12', Rule::in(['en', 'ar'])],
            'platform' => ['sometimes', Rule::in(['android', 'ios', 'web', 'unknown'])],
        ]);

        $device->forceFill([...$validated, 'last_seen_at' => now()])->save();

        return ApiResponse::success('Device updated.', new NotificationDeviceResource($device->fresh()));
    }

    public function destroy(Request $request, NotificationDevice $device): Response
    {
        $this->authorizeDevice($request, $device);

        $device->revoke();

        return response()->noContent();
    }

    /**
     * Unregister a token at logout, before the session is destroyed.
     *
     * Flutter may not have kept the numeric device id across a restart, but it
     * always has the token it was assigned. Only a token owned by the caller is
     * revoked; an unknown token still gets 204 so logout is idempotent.
     */
    public function unregister(Request $request): Response
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->notificationDevices()
            ->where('token_hash', NotificationDevice::hashToken($validated['token']))
            ->get()
            ->each(fn (NotificationDevice $device) => $device->revoke());

        return response()->noContent();
    }

    private function authorizeDevice(Request $request, NotificationDevice $device): void
    {
        abort_unless((int) $device->user_id === (int) $request->user()->getKey(), 404);
    }

    /**
     * When the same installation rotates its token, the old row for that
     * device_id is revoked. Otherwise both would stay active and Firebase would
     * deliver the alert twice.
     */
    private function revokeRotatedTokens(User $user, NotificationDevice $current): void
    {
        if (blank($current->device_id)) {
            return;
        }

        $user->notificationDevices()
            ->where('device_id', $current->device_id)
            ->whereKeyNot($current->getKey())
            ->active()
            ->get()
            ->each(fn (NotificationDevice $device) => $device->revoke());
    }
}
