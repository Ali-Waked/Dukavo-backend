# Email notification audit

This document records what the notification system looked like before the branded
email work, what was missing, and what was changed. It is a record of the audit,
not a second copy of the architecture guide. The current design is described in
[`EMAIL_NOTIFICATION_SYSTEM.md`](EMAIL_NOTIFICATION_SYSTEM.md).

## Scope

The audit covered every path in the backend that can produce an email:

- the notification dispatcher and its event catalogue;
- the email sender, mailable, queue job and delivery ledger;
- account and security mail (verification, password reset, invitations, new
  devices, role changes);
- order, inventory, messaging, AI approval, report and announcement emitters;
- provider delivery callbacks;
- configuration, localisation and the logo asset.

Billing email was checked only to decide whether it exists. It does not: the
billing provider is unset and paid subscriptions are not implemented, so no
billing template was added.

## Method

1. Read the existing notification, mail and delivery code end to end.
2. Trace every `NotificationIntent` emitter back to the business service that
   raises it, and check whether the event is dispatched after a commit.
3. Render the existing mail view and inspect the HTML and text output.
4. Compare the event catalogue with the lifecycle the product claims to cover.
5. Run the backend suite before changing anything, then after each addition.

## Findings before implementation

| Area | Finding | Risk |
| --- | --- | --- |
| Presentation | Email used a Laravel Markdown mail view (`mail.notification`) with no brand layout, logo or reusable components. | The product looked like a generic framework notification, not Dukavo. |
| Localisation | Subjects came from the stored notification copy, but the mail view itself had hard-coded English strings. | An Arabic recipient could receive English surrounding copy. |
| Direction | There was no `dir` or `lang` attribute, so Arabic rendered LTR. | Arabic email was unreadable for the intended audience. |
| Text fallback | Markdown mail produced an automatic text part, but the new branded content had no dedicated plain-text template. | Clients that prefer text would lose the message structure. |
| Event coverage | The catalogue covered orders, low stock, conversations, AI approvals and automation, but not order paid/shipped/delivered/cancelled/refunded, out-of-stock, restock reminders, stock adjustments, message failure, reports or announcements. | Several business events had no email at all. |
| Account/security | Verification and reset used framework defaults. Invitations, welcome, password change, new device and role change had no branded, tracked message. | Critical messages were inconsistent and unauditable. |
| Delivery tracking | `notification_deliveries` had status, attempts, provider message id and error, but no failure classification, no provider payload and no dedupe key for non-notification mail. | A permanent bounce looked exactly like a temporary outage. |
| Retry policy | The queue job retried every failure until the tries limit, then reported. Permanent recipient rejection burned quota and delayed the honest record. | Wasteful retries and a misleading audit trail. |
| Duplicate prevention | Notification dedupe existed per recipient. Account mail had no idempotency key. | A retried invitation or welcome could send twice. |
| After-commit dispatch | Delivery jobs were queued after the dispatcher's own transaction, but not necessarily after the caller's wider business transaction. | A rolled-back order could still send an email. |
| Preferences | Channel switches existed, but the catalogue did not define which events are preference-driven and which are operational. Maintenance could be muted through the generic path. | A security/operational notice could be suppressed, or marketing could be forced. |
| Provider receipts | There was no email webhook. `sent` was the final state the system could prove. | Bounces and complaints were invisible. |
| Reporting | No scheduled report email existed. | Merchants had to open the app to see a weekly/monthly summary. |
| Preview | No way to render a template in either language before sending. | Design and translation errors were found in production. |
| Logo | No email-specific logo asset was configured. | A local path would not load in a mail client. |
| Tests | Notification routing had coverage, but no email rendering, delivery, account outbox, webhook or scheduled-command tests. | The email layer was effectively unverified. |

## What was changed

### Presentation

- Added a branded HTML layout (`resources/views/emails/layout.blade.php`) with
  email-safe inline CSS, a 600px card, `dir`/`lang` attributes, a hidden
  preheader and no JavaScript.
- Added a plain-text template (`resources/views/emails/text.blade.php`) with the
  same data in a readable order and explicit action URLs.
- Added anonymous Blade components: `header`, `footer`, `button`, `card`,
  `status-badge`, `divider`, `summary-table` and `call-to-action`.
- Added group partials for orders, inventory, messages, AI, reports,
  announcements, account and generic content.
- Copied the real Dukavo logo to `public/images/email/dukavo-logo.png` and made
  the URL configurable, defaulting to `APP_URL/images/email/dukavo-logo.png`.

### Content and events

- Added `EmailContentBuilder`, which turns a notification or account template
  into the layout's content array. It re-reads the referenced order, inventory
  row, conversation or AI action inside that record's tenant context, so the
  email shows current real data rather than a dispatch-time guess.
- Extended the event catalogue with every required lifecycle event. Each event
  keeps its audience, permission, channels, preference key and EN/AR copy in one
  place.
- Added account templates: `welcome`, `invitation`, `verify`, `reset`,
  `password_changed`, `new_device` and `role_changed`.
- Wired the real triggers in order, inventory, conversation, AI, report,
  announcement, user, profile, login, password-reset and role services.
- Omitted billing email because billing is not implemented.

### Delivery

- Added a queued, tracked account outbox (`AccountEmailService`) that bypasses
  notification preferences but not the delivery ledger. Each row stores the
  template, its data and the action URL so the job can render after the request.
- Added `SendAccountEmail` and `NotificationMail`'s branded content path.
- Added `EmailFailureClassifier` and classified failures as `temporary` or
  `permanent`. Permanent failures are recorded once and not retried; temporary
  failures are retried with the configured backoff.
- Recorded the provider message id returned by `Mail::send()` so a later webhook
  can find the row.
- Deferred delivery jobs with `DB::afterCommit`, so an email is never sent for a
  transaction that rolls back.
- Added a unique `dedupe_key` (plus `failure_type`, `provider_payload`,
  `template` and `payload` columns) to `notification_deliveries`.
- Added a signed provider webhook for delivered/bounce/complaint/failed events.
  The endpoint is closed when no secret is configured.

### Operations

- Added `email:preview`, `email:report`, `email:restock-reminders` and
  `email:announce`.
- Scheduled weekly, monthly and performance reports plus the daily restock
  reminder in `routes/console.php`.
- Added EN and AR language files for every string a recipient sees.

## Verification

The full backend suite passed after the change:

```
vendor/bin/phpunit
676 tests, 3583 assertions
```

Email-specific coverage:

| File | Tests | What it proves |
| --- | --- | --- |
| `tests/Feature/EmailTemplateRenderingTest.php` | 9 | Every catalogue event renders in EN and AR with the logo, direction, real order/inventory/report data, safe message preview, preference links and tenant isolation. |
| `tests/Feature/EmailDeliveryTest.php` | 9 | Recipient/subject/content, after-commit queueing, duplicate prevention, muted preferences, forced maintenance, temporary retry, permanent stop, exhausted retries, terminal skip. |
| `tests/Feature/EmailAccountOutboxTest.php` | 6 | Tracked account mail, preference bypass, dedupe, missing address, real invitation link, deleted recipient. |
| `tests/Feature/EmailWebhookTest.php` | 7 | Closed endpoint, missing/wrong signature, delivered, bounce, temporary failure, unknown message id. |
| `tests/Feature/EmailScheduledCommandsTest.php` | 5 | Preview files in both languages, scheduled report from real orders, restock reminder, product opt-in, forced maintenance. |

`vendor/bin/pint --dirty --format agent` passes.

## Deliberately not done

- **No live provider verification.** No transactional provider credentials were
  available. The code path is exercised with the array mailer and `Mail::fake`;
  the first real send must still be confirmed against the provider.
- **No provider-specific webhook parser.** No provider is configured, so the
  endpoint accepts a normalized body. A provider-specific adapter is a small
  follow-up once a provider is chosen.
- **No billing email.** Billing is not implemented; adding templates would
  promise a feature the product cannot keep.
- **No browser screenshot pass.** The backend was not running and no browser was
  installed. Rendering is covered by the feature tests and the preview command.
- **No unsubscribe token.** Marketing email links to the notification settings
  page rather than a signed one-click unsubscribe URL. That is honest for the
  current preference model, but a public one-click link is the next step if
  product email becomes true marketing.

## Residual risks

- The first real provider send and the first real webhook delivery still need a
  smoke test in the deployment environment.
- `EmailFailureClassifier` is marker-based. Unknown errors are treated as
  temporary so nothing is silently dropped, but a provider with unusual wording
  may be retried once more than necessary.
- The logo URL depends on `APP_URL` being a public HTTPS origin in production.
  If it is not, the image will not load; set `DUKAVO_EMAIL_LOGO_URL` explicitly.
- Queue workers must be running for any non-synchronous delivery. Without them,
  rows stay `queued` — which is visible in the ledger, not hidden.
