<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The notification centre and bell.
 *
 * Every query is scoped through `$request->user()->notifications()`, so a
 * notification addressed to one account can never be read, counted or dismissed
 * by another: an id the caller does not own resolves to a 404 rather than a 403,
 * which keeps the existence of other accounts' notifications private. The
 * endpoints are personal, not tenant scoped — a multi-tenant user sees one bell
 * across all their workspaces.
 */
class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications()->latest('created_at')->latest('id');

        match ($request->status()) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            default => null,
        };

        $query
            ->when($request->category(), fn ($query, string $category) => $query->where('category', $category))
            ->when($request->severity(), fn ($query, string $severity) => $query->where('severity', $severity));

        return NotificationResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()->whereNull('read_at')->count();

        return ApiResponse::success('', ['count' => $count]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $model = $this->find($request, $notification);

        $model->markAsRead();

        return ApiResponse::success('Notification marked as read.', new NotificationResource($model));
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success('All notifications marked as read.', ['updated' => $updated]);
    }

    public function unread(Request $request, string $notification): JsonResponse
    {
        $model = $this->find($request, $notification);

        $model->markAsUnread();

        return ApiResponse::success('Notification marked as unread.', new NotificationResource($model));
    }

    public function destroy(Request $request, string $notification): Response
    {
        $this->find($request, $notification)->delete();

        return response()->noContent();
    }

    private function find(Request $request, string $id): Notification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
