<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationScope;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides who a notification is for, from the event's scope and permission.
 *
 * The audience is derived from role assignments rather than a hand-maintained
 * list, so it always matches what the permission layer would let someone do:
 * a platform event reaches platform operators and nobody inside a merchant,
 * while a tenant event reaches only members holding the event's permission in
 * *that* tenant. The actor who caused an event is excluded, because telling
 * someone about the thing they just did is noise.
 */
final class NotificationRecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(NotificationEvent $event, NotificationIntent $intent): Collection
    {
        return match ($event->scope) {
            NotificationScope::Platform => $this->platformAudience($intent),
            NotificationScope::Tenant => $this->tenantAudience($event, $intent),
            // Customer notifications are addresses, not users: they have no
            // in-app inbox, so there is no recipient model to resolve here.
            NotificationScope::Customer => new Collection,
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function platformAudience(NotificationIntent $intent): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active)
            ->when($intent->actorId !== null, fn ($query) => $query->whereKeyNot($intent->actorId))
            ->whereHas('roleAssignments', fn ($query) => $query->whereNull('tenant_id'))
            ->when(
                $intent->recipientIds !== null,
                fn ($query) => $query->whereIn('id', $intent->recipientIds ?? []),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function tenantAudience(NotificationEvent $event, NotificationIntent $intent): Collection
    {
        // A tenant event without a tenant or a permission has no defensible
        // audience: returning everyone would leak across workspaces.
        if ($intent->tenantId === null || $event->permission === null) {
            return new Collection;
        }

        return User::query()
            ->where('status', UserStatus::Active)
            ->when($intent->actorId, fn ($query) => $query->whereKeyNot($intent->actorId))
            ->whereHas('roleAssignments', function ($query) use ($event, $intent): void {
                $query->where('tenant_id', $intent->tenantId)
                    ->whereHas('role', fn ($role) => $role->whereHas(
                        'permissions',
                        fn ($permission) => $permission->where('slug', $event->permission),
                    ));
            })
            ->when(
                $intent->recipientIds !== null,
                fn ($query) => $query->whereIn('id', $intent->recipientIds ?? []),
            )
            ->orderBy('id')
            ->get();
    }
}
