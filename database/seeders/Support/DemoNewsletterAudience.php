<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * Demonstration newsletter subscribers.
 *
 * Every address uses a domain reserved for documentation and examples
 * (`example.com`, `example.org`, `example.net`), so none can reach a real
 * person or mailbox. The list is deliberately mixed: long-standing readers,
 * recent signups and people who left the list show the status and date columns
 * the platform admin screen renders without pretending to be production data.
 *
 * The schema has no pending-confirmation state — subscribing records consent
 * directly and there is no email provider yet — so records are only ever
 * `subscribed` or `unsubscribed`. An unsubscribed row keeps its original
 * consent date and adds the date it left, exactly as the public unsubscribe
 * flow would.
 */
final class DemoNewsletterAudience
{
    /**
     * @return list<array{email: string, status: string, source: string, consented_at: string, unsubscribed_at: string|null}>
     */
    public static function subscribers(): array
    {
        return [
            [
                'email' => 'urbancart@example.com',
                'status' => 'subscribed',
                'source' => 'website',
                'consented_at' => '2025-11-18 09:14:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'greenbasket@example.org',
                'status' => 'subscribed',
                'source' => 'blog',
                'consented_at' => '2025-12-03 14:27:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'nour.market@example.net',
                'status' => 'subscribed',
                'source' => 'referral',
                'consented_at' => '2025-12-19 11:42:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'bluepebble.store@example.com',
                'status' => 'subscribed',
                'source' => 'event',
                'consented_at' => '2026-01-12 16:05:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'cedarandsage@example.org',
                'status' => 'subscribed',
                'source' => 'newsletter-page',
                'consented_at' => '2026-02-05 08:38:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'atlas.supply@example.net',
                'status' => 'subscribed',
                'source' => 'blog',
                'consented_at' => '2026-02-27 13:19:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'morningbrew@example.com',
                'status' => 'subscribed',
                'source' => 'home',
                'consented_at' => '2026-03-16 07:51:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'lantern.goods@example.org',
                'status' => 'subscribed',
                'source' => 'footer',
                'consented_at' => '2026-04-09 18:24:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'harbor.retail@example.net',
                'status' => 'subscribed',
                'source' => 'waitlist',
                'consented_at' => '2026-05-21 10:06:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'olivebranch@example.com',
                'status' => 'subscribed',
                'source' => 'blog',
                'consented_at' => '2026-06-14 15:33:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'littlefox@example.org',
                'status' => 'subscribed',
                'source' => 'referral',
                'consented_at' => '2026-07-08 12:47:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'sundaymarket@example.net',
                'status' => 'subscribed',
                'source' => 'event',
                'consented_at' => '2026-08-19 09:58:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'paperplane@example.com',
                'status' => 'subscribed',
                'source' => 'website',
                'consented_at' => '2026-09-11 17:12:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'brightshelf@example.org',
                'status' => 'subscribed',
                'source' => 'home',
                'consented_at' => '2026-10-06 08:29:00',
                'unsubscribed_at' => null,
            ],
            [
                'email' => 'oldtowne@example.com',
                'status' => 'unsubscribed',
                'source' => 'website',
                'consented_at' => '2025-10-10 11:03:00',
                'unsubscribed_at' => '2026-01-22 14:16:00',
            ],
            [
                'email' => 'northstar@example.org',
                'status' => 'unsubscribed',
                'source' => 'blog',
                'consented_at' => '2025-12-28 16:41:00',
                'unsubscribed_at' => '2026-03-03 09:27:00',
            ],
            [
                'email' => 'pantrylane@example.net',
                'status' => 'unsubscribed',
                'source' => 'referral',
                'consented_at' => '2026-02-11 10:22:00',
                'unsubscribed_at' => '2026-04-27 19:05:00',
            ],
            [
                'email' => 'quickcart@example.com',
                'status' => 'unsubscribed',
                'source' => 'event',
                'consented_at' => '2026-04-02 13:54:00',
                'unsubscribed_at' => '2026-06-18 08:48:00',
            ],
            [
                'email' => 'willow.store@example.org',
                'status' => 'unsubscribed',
                'source' => 'newsletter-page',
                'consented_at' => '2026-05-09 15:36:00',
                'unsubscribed_at' => '2026-08-30 11:19:00',
            ],
        ];
    }
}
