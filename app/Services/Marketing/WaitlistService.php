<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Enums\WaitlistStatus;
use App\Http\Requests\Marketing\JoinWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEventCatalog as Events;
use App\Services\Notifications\NotificationIntent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Waitlist signups from the public marketing site.
 *
 * The only interesting case is "the same email twice": two tabs, a retried
 * request, or a visitor who simply forgot. The database's unique index is the
 * single arbiter — a lost race throws a `UniqueConstraintViolationException`,
 * which is caught and answered with the existing row, so the caller can treat
 * every outcome identically and never disclose whether the address was known.
 */
class WaitlistService
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    /**
     * Record a signup. `$attributes` is validated, already-normalised input
     * from {@see JoinWaitlistRequest}.
     */
    public function join(array $attributes): WaitlistEntry
    {
        try {
            $entry = WaitlistEntry::query()->create([
                'email' => $attributes['email'],
                'name' => $attributes['name'] ?? null,
                'business_type' => $attributes['business_type'] ?? null,
                'source' => $attributes['source'] ?? null,
                'status' => WaitlistStatus::Pending,
            ]);

            // Only a genuinely new signup alerts the platform; a duplicate
            // landing on the existing row must not page the team again.
            $this->notify($entry);

            return $entry;
        } catch (UniqueConstraintViolationException) {
            return WaitlistEntry::query()
                ->where('email', $attributes['email'])
                ->firstOrFail();
        }
    }

    private function notify(WaitlistEntry $entry): void
    {
        $this->notifications->dispatch(new NotificationIntent(
            eventKey: Events::PLATFORM_WAITLIST_RECEIVED,
            dedupeKey: 'platform.waitlist.received:'.$entry->getKey(),
            data: [
                'name' => $entry->name ?: $entry->email,
            ],
            actionUrl: '/waitlist-entries',
        ));
    }
}
