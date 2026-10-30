<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Database\Seeders\Support\DemoNewsletterAudience;
use Illuminate\Database\Seeder;

/**
 * Demonstration newsletter subscribers for the platform admin screen.
 *
 * Every address is on a reserved example domain, so these records are obviously
 * synthetic and cannot deliver mail to a real person. The seeder writes rows
 * only: it never calls the subscribe service, never queues a message and never
 * contacts an email provider, so running it cannot trigger a campaign.
 *
 * Idempotent by email. A second run updates the demonstration rows in place and
 * preserves the original timestamp of a subscriber that already exists, so
 * re-seeding does not rewrite consent history. Records with other addresses —
 * including real signups collected by the public form — are left untouched.
 *
 * Consent is only recorded where the data says the person subscribed, and an
 * unsubscribe timestamp is only written for rows whose status is
 * `unsubscribed`. The schema has no pending-confirmation state, so none is
 * invented here.
 */
class NewsletterSeeder extends Seeder
{
    public function run(): void
    {
        $inserted = 0;
        $updated = 0;

        foreach (DemoNewsletterAudience::subscribers() as $subscriber) {
            $status = NewsletterStatus::from($subscriber['status']);
            $unsubscribedAt = $status === NewsletterStatus::Unsubscribed
                ? $subscriber['unsubscribed_at']
                : null;

            $record = NewsletterSubscriber::query()->updateOrCreate(
                ['email' => $subscriber['email']],
                [
                    'status' => $status,
                    'source' => $subscriber['source'],
                    'consented_at' => $subscriber['consented_at'],
                    'unsubscribed_at' => $unsubscribedAt,
                ],
            );

            $record->wasRecentlyCreated ? $inserted++ : $updated++;
        }

        $this->command?->info(sprintf(
            'Newsletter: %d subscriber(s) inserted, %d updated (%d subscribed, %d unsubscribed).',
            $inserted,
            $updated,
            NewsletterSubscriber::query()->where('status', NewsletterStatus::Subscribed)->count(),
            NewsletterSubscriber::query()->where('status', NewsletterStatus::Unsubscribed)->count(),
        ));
    }
}
