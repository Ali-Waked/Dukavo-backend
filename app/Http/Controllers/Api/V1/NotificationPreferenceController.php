<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNotificationPreferencesRequest;
use App\Services\Profile\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification switches a user owns.
 *
 * Reads always return the *resolved* set (stored values merged over the model's
 * defaults), never the raw column: the screen should show a full set of switches
 * even for an account created before a new event existed.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success('', $request->user()->notificationPreferences());
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $this->profiles->updateNotificationPreferences($request->user(), $request->validated());

        return ApiResponse::success('Notification preferences saved.', $user->notificationPreferences());
    }
}
