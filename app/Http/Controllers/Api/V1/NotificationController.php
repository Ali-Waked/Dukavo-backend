<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Events\Notifications\NotificationStateChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
        $query = $request->user()->notifications()
            ->whereNull('archived_at')
            ->latest('created_at')
            ->latest('id');

        match ($request->status()) {
            'unread' => $query->whereNull('read_at'),
            'read' => $query->whereNotNull('read_at'),
            default => null,
        };

        $query
            ->when($request->category(), fn ($query, string $category) => $query->where('category', $category))
            ->when($request->severity(), fn ($query, string $severity) => $query->where('severity', $severity))
            ->when($request->scope(), fn ($query, string $scope) => $query->where('scope', $scope));

        return NotificationResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function show(Request $request, string $notification): NotificationResource
    {
        return new NotificationResource($this->find($request, $notification));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();

        return ApiResponse::success('', ['count' => $count]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $model = $this->find($request, $notification);

        $model->markAsRead();
        $this->announce($request, 'read', [$model->getKey()]);

        return ApiResponse::success('Notification marked as read.', new NotificationResource($model));
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $request->user()->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->update(['read_at' => now()]);

        $this->announce($request, 'read_all');

        return ApiResponse::success('All notifications marked as read.', ['updated' => $updated]);
    }

    public function unread(Request $request, string $notification): JsonResponse
    {
        $model = $this->find($request, $notification);

        $model->markAsUnread();
        $this->announce($request, 'unread', [$model->getKey()]);

        return ApiResponse::success('Notification marked as unread.', new NotificationResource($model));
    }

    public function destroy(Request $request, string $notification): Response
    {
        $model = $this->find($request, $notification);
        $model->delete();

        $this->announce($request, 'deleted', [$model->getKey()]);

        return response()->noContent();
    }

    /**
     * Tell the user's other open clients that read state changed.
     *
     * Only ids and the fresh count leave the server. The event is dispatched
     * after commit so another tab can never see a state the database rejected.
     *
     * @param  list<string>  $ids
     */
    private function announce(Request $request, string $action, array $ids = []): void
    {
        $user = $request->user();

        $count = $user->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->count();

        DB::afterCommit(fn () => event(new NotificationStateChanged($user, $action, $ids, $count)));
    }

    private function find(Request $request, string $id): Notification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }
}
