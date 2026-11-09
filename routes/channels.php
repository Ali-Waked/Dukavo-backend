<?php

use App\Models\Store;
use App\Models\User;
use App\Services\Rbac\PermissionService;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Every channel below is private. A browser may only subscribe to its own
| user channel, a workspace channel it actually belongs to, or the platform
| operators' channel. Merchant ids are never trusted from the client: each
| callback re-checks membership on the server.
|
*/

Broadcast::channel('App.Models.User.{userId}', function (User $user, int $userId): bool {
    return (int) $user->getKey() === $userId;
});

Broadcast::channel('tenant.{tenantId}', function (User $user, int $tenantId): bool {
    return $user->tenants()->whereKey($tenantId)->exists();
});

Broadcast::channel('store.{storeId}', function (User $user, int $storeId): bool {
    $store = Store::query()->withoutGlobalScopes()->find($storeId);

    return $store instanceof Store
        && $user->tenants()->whereKey($store->tenant_id)->exists();
});

Broadcast::channel('platform.admins', function (User $user): bool {
    return app(PermissionService::class)->isPlatformOperator($user);
});
