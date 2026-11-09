<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\NotificationIntent;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns a completed password reset into an in-app security notice.
 *
 * The reset itself is already the user's action, so this is not an approval
 * prompt: it is the "if this was not you" record that survives after the email
 * has been archived. The event is queued so a slow notification path can never
 * make the reset request wait, and it is addressed only to the affected user.
 */
final class NotifyPasswordReset implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // A security notice belongs to the person, not a workspace audience.
        // The first workspace is used only to satisfy the tenant-scoped row;
        // `recipientIds` keeps every other manager out of the audience.
        $tenant = $user->tenants()->orderBy('tenants.id')->first();

        $this->notifications->dispatch(new NotificationIntent(
            eventKey: NotificationEventCatalog::TENANT_SECURITY_PASSWORD_RESET,
            tenantId: $tenant?->getKey(),
            // Each reset is a distinct security event, so the claim must not
            // collapse two legitimate resets into one.
            dedupeKey: 'security.password.reset:'.$user->getKey().':'.now()->timestamp,
            data: [],
            actionUrl: '/settings/security',
            recipientIds: [(int) $user->getKey()],
        ));
    }
}
