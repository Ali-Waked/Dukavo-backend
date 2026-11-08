# Email notification system

Dukavo sends branded, localised transactional email through the existing Laravel
mail stack. This document describes how a business event becomes an email, how
delivery is tracked, what each template contains, and what is still unverified.

The audit that led to this design is in
[`EMAIL_NOTIFICATION_AUDIT.md`](EMAIL_NOTIFICATION_AUDIT.md).

## Design principles

1. **The in-app notification is the source of truth.** An email is an outbound
   copy of a notification, never a replacement for it. A muted preference or a
   failed transport never removes the bell entry.
2. **Only real data.** A template re-reads the order, inventory row, conversation
   or AI action inside its tenant context. Missing values are omitted, never
   invented.
3. **Only after commit.** Delivery jobs are deferred with `DB::afterCommit`, so a
   rolled-back business transaction cannot send an email.
4. **Claim first, then send.** A per-recipient claim row prevents duplicate
   notifications and enforces cooldowns for recurring events.
5. **Record what is proven.** `sent` means the provider accepted the message;
   `delivered` is reserved for a provider receipt. A failure is classified so an
   operator can tell a temporary outage from a rejected address.
6. **Security and operational mail is not optional.** Account, security and
   maintenance messages bypass notification preferences. Marketing obeys an
   opt-in preference.

## End-to-end flow

```
Business service (order, inventory, conversation, AI, report, user…)
        │  raises NotificationIntent
        ▼
NotificationDispatcher
        │  resolves recipients from roles + permission
        │  claims the event per recipient (dedupe / cooldown)
        │  writes the in-app notification
        │  writes one delivery row per available channel
        │  defers jobs with DB::afterCommit
        ▼
SendNotificationEmail (queue: notifications-mail)
        │  re-reads the delivery row
        │  refuses terminal or skipped rows
        ▼
EmailNotificationSender
        │  re-checks channel, address and verification
        │  builds NotificationMail
        ▼
EmailContentBuilder → emails.layout + emails.text
        │  re-reads tenant records for real data
        ▼
Laravel Mail transport (provider)
        │  returns a provider message id
        ▼
notification_deliveries.status = sent
        │
        └─ optional provider webhook → delivered / failed / bounced / complaint
```

Account and security messages use a parallel path:

```
UserService / User / ProfileService / LoginResponse / RoleService / Fortify
        │  calls AccountEmailService::send(...)
        ▼
AccountEmailService
        │  validates the template
        │  creates a delivery row (no notification id)
        │  applies the optional dedupe key
        │  skips immediately if the address is missing
        │  defers the job with DB::afterCommit
        ▼
SendAccountEmail → AccountEmail → same branded layout
```

## Event catalogue

The authoritative list is
`app/Services/Notifications/NotificationEventCatalog.php`. Every entry declares:

- `key` — the stable event name stored on the notification;
- `scope` — `platform` or `tenant`;
- `category` — the settings-screen grouping;
- `severity` — drives the status badge;
- `channels` — which transports may be used;
- `permission` — the permission a recipient must hold;
- `preference` — the switch that governs optional delivery, or `null` when the
  event is forced;
- `cooldownSeconds` — the minimum gap between repeated notifications;
- `messages` — EN and AR title/body copy.

### Order lifecycle

| Event | Trigger | Preference | Cooldown |
| --- | --- | --- | --- |
| `tenant.order.created` | `OrderService::create` | `order_updates` | none |
| `tenant.order.paid` | `OrderService::markPaid` | `order_updates` | none |
| `tenant.order.shipped` | status transition to shipped | `order_updates` | none |
| `tenant.order.delivered` | status transition to delivered | `order_updates` | none |
| `tenant.order.cancelled` | status transition to cancelled | `order_updates` | none |
| `tenant.order.refunded` | `OrderService::markRefunded` | `order_updates` | none |
| `tenant.order.status_changed` | any other transition | `order_updates` | none |

### Inventory

| Event | Trigger | Preference | Cooldown |
| --- | --- | --- | --- |
| `tenant.inventory.low_stock` | stock at or below threshold | `low_stock` | 24 hours |
| `tenant.inventory.out_of_stock` | available quantity ≤ 0 | `low_stock` | 24 hours |
| `tenant.inventory.restock_reminder` | `email:restock-reminders` | `low_stock` | 7 days |
| `tenant.inventory.adjusted` | manual stock adjustment | `low_stock` | none |

Out-of-stock takes precedence over low-stock: a row at zero is reported as out of
stock even when no threshold is configured.

### Messaging

| Event | Trigger | Preference |
| --- | --- | --- |
| `tenant.conversation.message_received` | inbound customer message | `new_messages` |
| `tenant.conversation.assigned` | conversation assigned | `conversation_assignments` |
| `tenant.conversation.attention` | conversation needs attention | `new_messages` |
| `tenant.message.failed` | outbound message failed | `delivery_failures` |

Message email contains a short preview only; the full conversation body is never
copied into an inbox.

### AI approvals and automation

| Event | Trigger | Preference |
| --- | --- | --- |
| `tenant.ai_action.awaiting_review` | AI action needs a human | `approvals` |
| `tenant.ai_action.approved` | action approved | `approvals` |
| `tenant.ai_action.rejected` | action rejected | `approvals` |
| `tenant.ai_action.failed` | action failed | `approvals` |
| `tenant.approval.request` | generic approval request | `approvals` |
| `tenant.automation.triggered` | automation ran | forced (no preference) |

### Reports

| Event | Trigger | Preference | Schedule |
| --- | --- | --- | --- |
| `tenant.report.weekly` | `email:report weekly` | `weekly_summary` | Monday 07:00 |
| `tenant.report.monthly` | `email:report monthly` | `weekly_summary` | 1st of month 07:30 |
| `tenant.report.performance` | `email:report performance` | `weekly_summary` | 1st of month 08:00 |

Report data comes from `ProfitIntelligenceService` inside the tenant context, in
the default store's currency (falling back to the tenant currency). Monthly
covers the previous completed calendar month; weekly and performance are rolling
windows ending today. No exchange rate is estimated and no currency is mixed.

### Announcements

| Event | Trigger | Preference | Forced? |
| --- | --- | --- | --- |
| `tenant.announcement.product` | `email:announce --type=product` | `product_news` | no |
| `tenant.announcement.release` | `email:announce --type=release` | `product_news` | no |
| `tenant.announcement.maintenance` | `email:announce --type=maintenance` | `null` | yes |

Product and release mail carries an unsubscribe link and is opt-out. Maintenance
is operational and cannot be muted.

### Platform events

`platform.waitlist.received`, `platform.contact.received`,
`platform.tenant.onboarded`, `platform.tenant.suspended` and
`platform.tenant.reactivated` target platform operators. Platform events have no
user-facing preference switch; they use the channels the event declares.

### Account and security templates

These are not notification events and have no preference switch:

| Template | Trigger |
| --- | --- |
| `welcome` | platform user created |
| `invitation` | `UserService::createForTenant` |
| `verify` | `User::sendEmailVerificationNotification` |
| `reset` | `User::sendPasswordResetNotification` |
| `password_changed` | profile password change or completed reset |
| `new_device` | first login from a new device name |
| `role_changed` | role assigned or revoked |

Account messages still create a `notification_deliveries` row with
`notification_id = null`. They bypass `require_verified_email` but require a
non-empty address; a missing address is recorded as `skipped` with
`email_missing`.

## Presentation layer

### Layout

`resources/views/emails/layout.blade.php` is a table-based, inline-styled HTML
document:

- `<!DOCTYPE html>`, `lang` and `dir` from the recipient's locale;
- a hidden preheader;
- a 600px white card on a light background;
- the branded header, content group, CTA and footer;
- a light-only colour scheme and no JavaScript.

`resources/views/emails/text.blade.php` is the plain-text alternative. It repeats
the heading, intro, summary, tables, notes, CTA URL and footer links in a readable
order.

### Components

All components are anonymous Blade components under
`resources/views/components/email/`:

| Component | Purpose |
| --- | --- |
| `header` | Dukavo logo and tagline on the brand colour |
| `footer` | Footer note, support link, preferences/unsubscribe links, security note |
| `button` | Bulletproof CTA button with a plain link fallback |
| `card` | White content surface with consistent padding |
| `status-badge` | Severity/status pill |
| `divider` | Subtle horizontal rule |
| `summary-table` | Label/value rows in a two-column table |
| `call-to-action` | CTA block with an optional note |

### Content groups

`EmailContentBuilder::groupFor()` maps an event key to one of:

`orders`, `inventory`, `messages`, `ai`, `reports`, `announcements`, `account`,
`generic`.

Each group has a partial in `resources/views/emails/groups/`. Shared section
rendering lives in `_sections.blade.php`.

### Brand tokens

| Token | Value |
| --- | --- |
| Primary | `#0F766E` |
| Dark | `#115E59` |
| Accent | `#A3E635` |
| Content surface | `#FFFFFF` |
| Page background | `#F1F5F9` |

The logo is a real Dukavo asset at
`public/images/email/dukavo-logo.png` (500×500). The header uses
`config('dukavo.email.logo_url')`, which defaults to
`APP_URL/images/email/dukavo-logo.png`.

## Localisation and direction

- `lang/en/email.php` and `lang/ar/email.php` hold every visible string.
- The recipient's `users.locale` selects the language.
- An unsupported or empty locale falls back to `dukavo.locales.default` (`en`).
- The layout sets `dir="rtl"` and right alignment for Arabic, `dir="ltr"` and left
  alignment for English.
- The text template uses the same locale and date/number formatting as the HTML.
- `EmailContentBuilder` translates labels with `Lang::get(..., locale)`, so a
  missing key falls back to the default language rather than rendering empty.

## Recipients and preferences

`NotificationRecipientResolver` derives the audience from role assignments:

- a platform event reaches platform operators;
- a tenant event reaches members holding the event's permission **in that
  tenant**;
- the actor who caused the event is excluded;
- `recipientIds` on the intent can narrow the audience.

`User::notificationPreferences()` merges stored switches with permission-aware
defaults. A channel is used only when:

1. the event declares it;
2. the channel is configured (`dukavo.notifications.channels.*.enabled`);
3. the recipient's preference for that event is `true`;
4. the destination exists (email address, verified where required, or phone).

When any of those fails, the delivery row is written as `skipped` with a reason
and no job is dispatched.

## Delivery ledger

Every outbound attempt is a `notification_deliveries` row. The migration
`2026_11_08_000000_add_delivery_tracking_to_notification_deliveries_table.php`
adds:

| Column | Purpose |
| --- | --- |
| `failure_type` | `temporary` or `permanent` |
| `provider_payload` | The webhook body that changed the state |
| `dedupe_key` | Idempotency key for account mail (unique) |
| `template` | Account template name |
| `payload` | Account template data and action URL |

States:

| Status | Meaning |
| --- | --- |
| `pending` | Created, not yet queued |
| `queued` | A job has been dispatched |
| `processing` | The job is currently sending |
| `sent` | The transport accepted the message |
| `delivered` | A provider receipt confirmed delivery |
| `failed` | Transmission failed; see `error` and `failure_type` |
| `skipped` | Deliberately not sent; see `error` |
| `cancelled` | Abandoned before an attempt |

`recipient_ref` stores a masked destination (`l•••@example.com`, `•••1234`) so
an operator can inspect activity without exposing the address.

## Retry and failure handling

Both queue jobs read `dukavo.notifications.retry`:

- `times` defaults to `3`;
- `backoff` defaults to `[60, 300]` seconds.

`EmailFailureClassifier` inspects the exception message:

- **Permanent** markers include `550 `, `invalid recipient`, `invalid email`,
  `blocked`, `complaint` and similar hard failures. The delivery is marked failed
  once, the exception is reported, and the job returns without retrying.
- **Temporary** failures (timeouts, `4xx`, unknown errors) mark the delivery
  failed and rethrow so the queue retries with backoff. After the final attempt
  the delivery stays failed and the exception is reported.
- A job whose delivery is already terminal, or already `skipped`, returns
  immediately. A duplicated queue entry is therefore harmless.

Failures are never silently swallowed: they update the ledger and are reported to
the application's exception handler.

## Duplicate prevention and cooldowns

Two separate mechanisms:

1. **Notification events.** `NotificationEventKey` stores a per-recipient key.
   A one-shot event is refused forever after the first claim. A recurring event
   is refused until its cooldown has elapsed. The unique key plus a row lock makes
   the claim atomic under concurrent dispatch.
2. **Account mail.** `AccountEmailService` accepts an optional `dedupeKey`.
   Invitations use `account.invitation:{userId}:{tenantId}`; welcome uses
   `account.welcome:{userId}`. The unique index on `dedupe_key` makes a retried
   insertion return the existing row instead of sending twice.

## Commands and schedule

| Command | Purpose |
| --- | --- |
| `email:preview {template} {--locale=en} {--output=}` | Render a sample HTML and text file for review |
| `email:report {period} {--tenant=}` | Dispatch weekly, monthly or performance reports |
| `email:restock-reminders {--tenant=}` | Remind tenants about inventory still below threshold |
| `email:announce {--tenant=} {--type=} {--title=} {--summary=}` | Send a product, release or maintenance announcement |

Scheduled in `routes/console.php`:

```php
Schedule::command('email:report weekly')->weeklyOn(1, '07:00')->withoutOverlapping();
Schedule::command('email:report monthly')->monthlyOn(1, '07:30')->withoutOverlapping();
Schedule::command('email:report performance')->monthlyOn(1, '08:00')->withoutOverlapping();
Schedule::command('email:restock-reminders')->dailyAt('09:00')->withoutOverlapping();
```

Every command iterates tenants and establishes each tenant's context before
reading data or resolving recipients.

### Previewing a template

```bash
php artisan email:preview tenant.order.paid --locale=en
php artisan email:preview tenant.order.paid --locale=ar
php artisan email:preview account.invitation --locale=ar
```

Files are written to `storage/app/email-previews` by default, named
`<template>-<locale>.html` and `.txt`. The preview uses sample business data
only; it never reads a real recipient and never sends.

## Provider webhook

`POST /api/v1/webhooks/email` (route name `webhooks.email`) accepts delivery
receipts.

**Authentication**

- Header `X-Dukavo-Signature: sha256=<hex>` where the hex is
  `hash_hmac('sha256', rawBody, DUKAVO_EMAIL_WEBHOOK_SECRET)`.
- If the secret is unset, the endpoint returns `403` and logs an error.
- A missing or wrong signature returns `401`.

**Body (normalized)**

```json
{
  "message_id": "provider-message-id",
  "event": "delivered",
  "reason": "optional human-readable reason",
  "permanent": false
}
```

Aliases: `provider_message_id` for the id, `type` for the event.

**Event mapping**

| Event | Result |
| --- | --- |
| `delivered` | `status = delivered`, stores the payload |
| `bounce`, `bounced`, `complaint`, `complained`, `rejected`, `dropped` | `status = failed`, `failure_type = permanent` |
| `failed` | `status = failed`, `failure_type` from `permanent` (default temporary) |
| anything else | acknowledged, no change |

Unknown message ids and malformed bodies are acknowledged without creating a
delivery, so a provider does not redeliver forever.

Because no provider is configured, the controller accepts this normalized shape
rather than a provider-specific schema. Add a parser once a provider is chosen.

## Configuration

| Env var | Config key | Default | Notes |
| --- | --- | --- | --- |
| `MAIL_*` | `mail.*` | Laravel defaults | The actual transport credentials |
| `DUKAVO_EMAIL_LOGO_URL` | `dukavo.email.logo_url` | `APP_URL/images/email/dukavo-logo.png` | Must be absolute and public in production |
| `DUKAVO_EMAIL_LOGO_WIDTH` | `dukavo.email.logo_width` | `168` | Header width in px |
| `DUKAVO_EMAIL_WEBHOOK_SECRET` | `dukavo.email.webhook_secret` | unset | Webhook is closed when unset |
| `DUKAVO_SUPPORT_EMAIL` | `dukavo.email.support_email` | `MAIL_FROM_ADDRESS` | Footer support link |
| `FRONTEND_URL` | `dukavo.urls.frontend` | `APP_URL` | Where email CTAs land |
| `DUKAVO_NOTIFICATION_MAIL` | `dukavo.notifications.channels.mail.enabled` | `true` | Master switch |
| `DUKAVO_NOTIFICATION_REQUIRE_VERIFIED_EMAIL` | `dukavo.notifications.require_verified_email` | `true` | Skip unverified addresses |
| `DUKAVO_NOTIFICATION_RETRY_TIMES` | `dukavo.notifications.retry.times` | `3` | Queue tries |

`APP_URL` must be the public HTTPS origin in production. `FRONTEND_URL` must be
the human-facing app origin; the API URL is not a valid CTA destination.

## Adding an event

1. Add a constant and a `NotificationEvent` entry to
   `NotificationEventCatalog`, including EN/AR copy, permission, preference,
   channels and cooldown.
2. Raise a `NotificationIntent` from the service that changes the state, after
   the transaction commits (or inside it — the dispatcher defers jobs itself).
3. If the event needs richer content, add a branch to the matching group in
   `EmailContentBuilder`.
4. Add the labels to both `lang/en/email.php` and `lang/ar/email.php`.
5. Add a rendering test and a delivery test.

## Adding an account template

1. Add the template name to `AccountEmailService::TEMPLATES`.
2. Add heading/intro/CTA/rows copy to both language files and the
   `accountHeading`/`accountRows` maps in `EmailContentBuilder`.
3. Call `AccountEmailService::send()` from the trigger, with a dedupe key if the
   message is one-shot.
4. Add it to `EmailAccountOutboxTest`.

## Testing

```bash
vendor/bin/phpunit tests/Feature/EmailTemplateRenderingTest.php
vendor/bin/phpunit tests/Feature/EmailDeliveryTest.php
vendor/bin/phpunit tests/Feature/EmailAccountOutboxTest.php
vendor/bin/phpunit tests/Feature/EmailWebhookTest.php
vendor/bin/phpunit tests/Feature/EmailScheduledCommandsTest.php
```

The suite uses the array mailer, the sync queue and in-memory SQLite. Tests prove
rendering, direction, logo URL, recipients, subjects, real business data, queueing
after commit, duplicate prevention, preference enforcement, tenant isolation,
retry/permanent failures and webhook signatures.

## Limitations

- **No live provider verification.** The transport path is tested with the array
  mailer and `Mail::fake`. The first real send in a deployment must be confirmed
  against the provider.
- **Generic webhook only.** There is no provider-specific payload parser until a
  provider is chosen.
- **No billing email.** Billing is not implemented.
- **No signed one-click unsubscribe.** Marketing links point to
  `/settings/notifications`.
- **Queue workers required.** Non-synchronous deliveries stay `queued` until a
  worker runs the `notifications-mail` queue.
- **Classification is marker-based.** Unfamiliar permanent errors are treated as
  temporary and retried up to the configured limit.
- **No browser screenshot pass.** The preview command renders the same views a
  recipient receives; use it for visual review.
