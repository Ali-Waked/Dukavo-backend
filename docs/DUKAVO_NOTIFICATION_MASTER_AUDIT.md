# Dukavo notification platform — master audit

**Audit date:** 9 November 2026
**Scope:** Laravel backend, Vue 3 web app, Flutter mobile app, MySQL,
Laravel Reverb, Firebase Cloud Messaging, email and platform administration.
**Repositories:** `backend/`, `frontend/`, `mobile/`
**Reference documents:** [DUKAVO_NOTIFICATION_PLATFORM.md](DUKAVO_NOTIFICATION_PLATFORM.md),
[DUKAVO_NOTIFICATION_API.md](DUKAVO_NOTIFICATION_API.md)

> **Verdict.** The notification ecosystem is implemented end to end and verified
> in the test environment. The external transports — FCM, Reverb, SMTP and
> WhatsApp — are **not live-verified**: this environment has no Firebase
> project, no Reverb server/key, no SMTP relay and no approved WhatsApp
> template. Code and fake-transport tests are evidence that the design is
> coherent, not evidence that an external provider delivered a message. See
> [Verification and unverified integrations](#12-verification-and-unverified-integrations).

---

## 1. Audit method

1. Read the existing notification architecture before changing it and extended
   it rather than replacing it.
2. Traced every required capability to a concrete migration, model, service,
   job, controller, route, permission, UI surface and test.
3. Ran the focused notification/email suite, then the full backend suite.
4. Ran the Vue type-check and the frontend unit tests.
5. Verified the Flutter notification paths with targeted `flutter analyze` and
   `flutter test` in a clean worktree.
6. Recorded every integration that could not be configured in this environment
   as **not verified**, without softening the claim.

Static analysis and tests are necessary but not sufficient. A requirement is
only marked “verified” below when the behaviour is exercised by a test or a
successful local run. A live-provider requirement remains “not verified” even
when its code path is covered by a fake transport.

---

## 2. Architecture summary

```text
Domain service (order, stock, conversation, AI, team, security, reports)
        │  NotificationIntent(eventKey, tenant/store, data, action, dedupe)
        ▼
NotificationDispatcher
        │  catalogue: category, severity, channels, preference, permission
        │  recipient resolver: role/permission/tenant/store scope
        ▼
MySQL transaction
        ├── notifications                 (authoritative in-app record)
        ├── notification_deliveries       (per-channel attempt state)
        └── notification_event_keys       (one-shot / cooldown idempotency)
        │
        ├── after commit → NotificationCreated ──► Reverb private user channel
        └── after commit → queue jobs
                              ├── notifications-mail
                              ├── notifications-push   → FCM → devices
                              └── notifications-whatsapp
```

Platform campaigns reuse the notification and delivery tables and add a
`notification_campaigns` row plus one `notification_campaign_recipients` row per
resolved user. Campaign batches run on `notifications-campaigns` with rate
limiting and recipient deduplication.

The source-of-truth boundary is deliberate:

- **MySQL** owns notification history, read state, preferences, consent,
  device registration, campaign state and delivery state.
- **Reverb** is a web delivery accelerator only.
- **FCM** is a push transport only. No product behaviour reads state from
  Firebase, and there is no Firebase Realtime Database.
- **Laravel Mail** is an email transport only.
- **WhatsApp** is an optional transport; when unavailable the channel is
  reported unavailable and deliveries are stored as `skipped`, never faked.

---

## 3. Requirement audit

Legend: **Verified** = code exists and was exercised by tests or a successful
local check. **Not verified** = code exists but depends on an external service
or physical device that is absent here. **Not applicable** = the product has no
such domain, so inventing it would be dishonest.

### 3.1 Central engine and dispatch

| Requirement | Evidence | Status |
| --- | --- | --- |
| One central notification engine | `NotificationDispatcher`, `NotificationIntent`, `NotificationEventCatalog`, `NotificationRecipientResolver` | Verified |
| After-commit dispatch | `DB::afterCommit` for broadcasts and send jobs; rollback tests | Verified |
| Event/listener/service/job architecture | Domain events → listeners/services → intents → dispatcher → channel jobs | Verified |
| Per-channel delivery tracking | `notification_deliveries` row per outbound channel with lifecycle states | Verified |
| Lifecycle states | `pending`, `queued`, `processing`, `sent`, `delivered`, `failed`, `skipped`, `cancelled` | Verified |
| Duplicate suppression | `notification_event_keys`, unique `(notification_id, channel)`, campaign recipient uniqueness, dedupe keys | Verified |
| Recurring-event cooldowns | Low stock once per inventory row per day; restock reminder once per seven days | Verified |
| Real domain events only | Event catalogue contains order, inventory, conversation/message, AI, team, security, report, announcement, platform lead/tenant events | Verified |
| No invented billing events | Billing does not exist in the product; no billing notification or plan targeting was added | Not applicable |

### 3.2 Channels

| Requirement | Evidence | Status |
| --- | --- | --- |
| MySQL is source of truth | Notification, preference, delivery and campaign state all stored in MySQL | Verified |
| FCM push delivery | `SendNotificationPush`, `PushTransport`, device registration, token rotation/revocation, invalid-token cleanup | Code verified with fake transport; **live delivery not verified** |
| Multi-device push | One row per device, token hash uniqueness, stable installation id | Verified |
| Android channels | `orders`, `inventory`, `messages`, `ai`, `security`, `reports`, `announcements` match backend and Flutter | Verified |
| Push error/retry logging | Delivery status, attempts, error, failure type, provider message id | Verified |
| Web realtime via Reverb | Private user/tenant/store/platform channels, dynamic bearer authoriser, reconnect resync | Code verified; **live Reverb not verified** |
| Polling fallback | Vue 60-second unread poll; Reverb disabled when key is empty | Verified |
| Email via Laravel Mail | Branded EN/AR RTL/LTR layout, real logo, real data, stored currency values | Verified in tests with array mailer; **live SMTP not verified** |
| WhatsApp | Catalogue events declare the channel; unavailable transport produces `skipped`, never a fake send | Code verified; **live transport not verified** |

### 3.3 Email system

| Requirement | Evidence | Status |
| --- | --- | --- |
| Branded EN/AR RTL/LTR emails | `emails/layout.blade.php`, `emails/text.blade.php`, real Dukavo logo asset | Verified |
| Real data only | Templates receive domain data; report previews use the empty state rather than invented figures | Verified |
| Historical currency values | Notification data stores the currency at event time; reports use tenant/report currency rules | Verified |
| Account mail | Welcome, invitation, verification, reset, password changed, new device, role changed | Verified |
| Notification mail | Order, inventory, conversation, AI, team, report and announcement templates | Verified |
| Plain-text alternative | Every mail has an `emails/text` render | Verified |
| Marketing headers | `List-Unsubscribe` and `List-Unsubscribe-Post: One-Click` on marketing mail | Verified |
| Platform template management | List, EN/AR preview, subject/heading/intro editing, enable/disable optional, test send, restore defaults | Verified |
| No raw HTML from operators | Plain-text fields only; branded layout stays in code; preview in sandboxed iframe | Verified |
| Mandatory templates cannot be disabled | Catalogue `optional` flag enforced server-side; security/account mail always on | Verified |

### 3.4 Platform administration

| Requirement | Evidence | Status |
| --- | --- | --- |
| Platform-only access | `EnsurePlatformAccess` + `notifications.view` / `notifications.manage` | Verified |
| Delivery monitoring | Notification/recipient/tenant/channel/provider/status/attempt/error/time, filters, pagination | Verified |
| Safe retry | Failed, non-permanent, mail/push only; reset to queued; one job after commit; refusal codes | Verified |
| Campaign draft/preview/schedule/publish/cancel | `NotificationCampaignService`, lifecycle enum and guarded transitions | Verified |
| Audience targeting | `all`, `tenants`, `users`, `exclude_user_ids`; platform staff excluded | Verified |
| Batching and rate limits | `notifications-campaigns` queue, chunked audience, per-minute limiter | Verified |
| Audience preview and count | `/preview` and `/audience-count` against real users | Verified |
| Duplicate-free delivery | Recipient ledger, notification dedupe key, delivery uniqueness, idempotency key | Verified |
| Audit logs | Campaign create/update/schedule/publish/cancel; email template update/restore; delivery retry | Verified |
| Vue campaign admin | `NotificationCampaignsPage.vue` | Verified by type-check and tests |
| Vue email template admin | `EmailTemplatesPage.vue` | Verified by type-check and tests |
| Vue delivery monitor | `NotificationDeliveryMonitorPage.vue` | Verified by type-check and tests |

### 3.5 Clients

| Requirement | Evidence | Status |
| --- | --- | --- |
| Vue notification centre | Bell, dropdown, centre, filters, read/unread/delete, pagination | Verified by type-check |
| Vue preferences | Per-channel/event switches, mandatory security, unavailable-channel reasons | Verified by type-check |
| Vue realtime | Lazy Echo/Reverb, bearer authoriser, reconnect resync, polling fallback | Code verified; **live Reverb not verified** |
| Flutter notification centre | Status/category/severity filters, paging, read/unread/delete, empty/error states | Verified by targeted analysis/tests |
| Flutter push lifecycle | Initialise after first frame, permission, token registration/refresh, logout revoke | Code verified; **live FCM not verified** |
| Flutter deep links | Same-origin URLs or in-app paths only; fallback to notification detail/list | Verified by tests |
| Flutter detail screen | Cold deep link fetch, mark read once, open related | Verified by targeted analysis/tests |
| Flutter preferences | Channel/event switches, mandatory security, EN/AR, light/dark | Verified by targeted analysis/tests |
| Android notification channels | Channel ids match backend category mapping | Verified by code inspection |
| iOS push | `UIBackgroundModes` present; capability and APNs key are deployment configuration | **Not configured** |

### 3.6 Marketing consent and unsubscribe

| Requirement | Evidence | Status |
| --- | --- | --- |
| Marketing opt-in | `product_news` defaults off on every channel, including in-app | Verified |
| Secure unsubscribe tokens | SHA-256 hash at rest, single-purpose, user+channel+category scoped, expiring | Verified |
| Consent tracking | Immutable `notification_consents` rows with granted/revoked, source, IP, user agent | Verified |
| Mandatory security notices | `security_alerts` forced on; not consent-based; never in unsubscribe scope | Verified |
| One-click unsubscribe | RFC 8058 `POST` route; replayed `POST` idempotent; `GET` read-only | Verified |
| Public unsubscribe UI | `/unsubscribe?token=...` and `/unsubscribe/{token}` | Verified by type-check |
| No enumeration via link | `GET` returns masked email; unknown token returns `410` | Verified |
| Unsubscribe rate limit | 10/min per IP | Verified |

### 3.7 Reports

| Requirement | Evidence | Status |
| --- | --- | --- |
| Weekly/monthly digests | `email:report {weekly|monthly|performance}` | Verified |
| Timezone-aware periods | Weekly rolling seven days ending at tenant “today”; monthly previous calendar month; performance rolling 30 days | Verified |
| Correct historical financial data | Values come from stored order/refund/cost data; missing values stay empty | Verified |
| EN/AR templates | Catalogue messages and email layout support both locales | Verified |
| Idempotent reruns | Dedupe key = event + tenant + hash of computed period | Verified |

---

## 4. Data model

| Table | Purpose |
| --- | --- |
| `notifications` | Authoritative in-app record: UUID, recipient, tenant/store scope, event, category, severity, priority, title/body, action URL, data, related record, read/archived, dedupe key. |
| `notification_deliveries` | One row per outbound channel attempt: status, attempts, error, failure type, provider ids, timestamps, unique `(notification_id, channel)`. |
| `notification_event_keys` | Idempotency ledger for one-shot events and cooldowns, keyed by event + recipient (+ related scope). |
| `notification_devices` | Push installations: user, stable device id, platform, token, token hash, metadata, last seen, revoked. |
| `notification_campaigns` | Campaign intent and progress, audience snapshot, lifecycle, counters, idempotency key. |
| `notification_campaign_recipients` | Per-recipient ledger with status and error; unique `(campaign_id, user_id)`. |
| `notification_email_templates` | Stored plain-text overrides per template, enabled flag for optional templates, actor and timestamps. |
| `notification_unsubscribe_tokens` | Hashed single-purpose token, user, channel, category, expiry, used timestamp. |
| `notification_consents` | Immutable marketing-consent history: user, email, channel, category, action, source, IP, user agent, metadata. |

Migrations are additive. Existing notification rows and indexes are retained.

---

## 5. Security review

| Control | Assessment |
| --- | --- |
| Recipient scoping | Personal reads use `$request->user()->notifications()`. Foreign ids return 404. |
| Tenant/store isolation | Recipient resolution and permissions are server-side. Broadcast callbacks re-check membership; client-supplied ids are not trusted. |
| Platform isolation | Platform middleware plus explicit permission on campaigns, email templates, delivery monitoring and retry. Merchant tokens cannot reach them. |
| Device ownership | List/update/delete are scoped to the caller. A token claimed by another account is revoked from the previous owner first. |
| Token handling | FCM tokens are stored and hashed, never returned by the API or serialised into resources. |
| Push payload minimisation | Data payload carries ids and routing metadata only; no customer names, totals, credentials or tokens. |
| Realtime payload minimisation | Personal content only on the user's private channel; state events carry ids and counts only. |
| Broadcast authorisation | Bearer-authenticated API stack; each channel callback verifies user/tenant/store/platform role. |
| Email template safety | Operators submit plain text, not HTML. Only catalogue-approved placeholders are accepted. The branded layout remains in code. |
| Email preview safety | HTML is rendered in a sandboxed iframe. |
| Unsubscribe token safety | SHA-256 hashed at rest, single-purpose, scoped to user + channel + `product_news`, expiring, rate limited, masked address on `GET`. |
| Unsubscribe scope | Only the optional category the token was issued for can be changed. Security/account mail cannot be disabled. |
| Consent history | Append-only rows for marketing consent changes; mandatory notices deliberately excluded. |
| Deliverability truth | `sent` means provider acceptance; `delivered` requires a receipt; `skipped` is a deliberate non-send; test sends report `delivery_confirmed: false`. |
| Rate limiting | Device registration per account; unsubscribe per IP; campaign jobs batched and rate limited; normal API middleware elsewhere. |
| Audit trail | Campaign lifecycle, email template update/restore and delivery retry recorded through `AuditLogger` without sensitive payloads. |
| Credential hygiene | FCM credentials come from environment/config. No credential material is tracked. `.env.example` uses empty placeholders. |
| Idempotency | Event claims, campaign recipient uniqueness, delivery uniqueness, campaign dedupe keys, idempotency keys, replayed unsubscribe. |

Residual risks:

- A platform operator with `notifications.view` can see masked recipient
  references and delivery errors. This is intentional operational access, but
  it should be limited to staff who need it.
- Test-send and preview use sample data; they do not prove a production mail
  path.
- Browser/mobile runtime UI was not manually exercised in this environment.

---

## 6. Test evidence

### 6.1 Backend

| Run | Command | Result |
| --- | --- | --- |
| Focused notification/email suite | `php -d memory_limit=1G vendor/bin/phpunit` on notification/email tests | **128 tests, 1080 assertions passed** |
| Full backend suite | `php -d memory_limit=1G vendor/bin/phpunit` | **752 tests, 4043 assertions passed** |
| Formatting | `vendor/bin/pint --format agent` on changed files | Passed |

Focused coverage includes:

- notification centre, preferences, device registration/rotation/revocation;
- dispatcher idempotency, cooldowns, opt-out and mandatory security;
- channel availability and fake FCM/WhatsApp transports;
- campaign lifecycle, audience resolution, consent gating, batching and stats;
- broadcast authorization for user/tenant/store/platform channels;
- scheduled reports and retention;
- email template catalogue, overrides, validation, preview, test send and
  restore;
- delivery monitoring filters, privacy shape and retry eligibility/refusals;
- public unsubscribe `GET`/`POST`, expiry, replay idempotency, consent history
  and rate-limit behaviour.

The default 128 MB CLI memory limit is too low for this route file. Use
`php -d memory_limit=1G vendor/bin/phpunit` (or `artisan test` with the same
override). Piping PHPUnit output through `head`/`tail` can appear to hang;
redirect to a file and inspect it after completion.

### 6.2 Frontend (Vue)

| Run | Result |
| --- | --- |
| `npm run type-check` | Passed |
| `npm test` (Node test runner) | **27 tests passed** |

Relevant tests include notification navigation safety (same-origin/in-app only,
protocol-relative and `javascript:` refused), localized content fallback and
avatar crop behaviour. The new admin pages are included in the type-check.

### 6.3 Mobile (Flutter)

| Run | Result |
| --- | --- |
| `flutter analyze lib/features/notifications test/features/notifications` | No issues |
| `flutter test test/features/notifications/notification_test.dart` | **6 tests passed** |

This verification was run in a clean worktree at the notification commit
(`13d4188`). Mobile `HEAD` has since moved to `cc84165`; a full-project mobile
analysis/test run was not repeated after that move. The notification paths were
verified as described, and the working tree was clean.

---

## 7. Requirements deliberately not invented

The audit found no billing/subscription domain. Accordingly:

- no billing notification events were invented;
- no subscription-plan audience targeting was added;
- campaign audiences are only `all`, `tenants` and `users`;
- reports expose only values the platform actually stores and leave missing
  values empty instead of turning them into a misleading zero.

The same principle applies to external delivery: a missing FCM/Reverb/SMTP/
WhatsApp configuration is reported as unavailable, not simulated. Push delivery
rows are omitted when Firebase is globally unconfigured; a disabled optional
email template records a skip rather than a fake send.

---

## 8. Consent and lifecycle semantics

### 8.1 In-app opt-out

The authoritative row is still written with `archived_at = now()`. The item is
hidden from the bell and list but retained for audit and for any outbound
channel the user still allows. This is an opt-out, not a delete.

### 8.2 Marketing

`product_news` is the only marketing category. It is opt-in on every channel,
including in-app. A campaign marked `is_marketing` is skipped per channel when
the recipient has not consented. `exclude_user_ids` is applied after audience
resolution.

### 8.3 Mandatory notices

`security_alerts` is forced on for every channel. Account/security email
templates are mandatory and cannot be disabled from the platform template
admin. The unsubscribe link is scoped to `product_news` only and says so on the
page.

### 8.4 Consent history

Every marketing consent change writes an immutable `notification_consents` row:
`granted` or `revoked`, source (`profile` or `unsubscribe_link`), channel,
category, optional IP/user agent and metadata. Mandatory notices are excluded
because they are not consent-based.

---

## 9. Delivery truth and operator safety

Operators see four distinct facts and the UI keeps them separate:

| Fact | State | Claim |
| --- | --- | --- |
| Notification created | row exists | MySQL has the in-app record. |
| Delivery queued | `queued` | A job exists; it may not have run. |
| Provider accepted | `sent` | The transport accepted the message. |
| Provider confirmed delivery | `delivered` | A receipt says it arrived. |
| Deliberate non-send | `skipped` | No consent, no destination, or channel unavailable. |

Retry is narrow by design: only a recorded, non-permanent failure on mail or
push can be retried. The row leaves `failed` before the response returns, so a
double click cannot enqueue two sends. WhatsApp is not retryable because there
is no configured transport to retry to.

A test email response always says `delivery_confirmed: false`. A campaign’s
`sent_count` is provider acceptance, not proof every recipient read the mail.

---

## 10. Operational requirements

```bash
php artisan migrate --force

php artisan queue:work --queue=notifications-mail,notifications-push,notifications-whatsapp --tries=3
php artisan queue:work --queue=notifications-campaigns --tries=3

* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
php artisan reverb:start --host=0.0.0.0 --port=8080   # only when BROADCAST_CONNECTION=reverb
```

- Queue workers need the same Firebase and mail environment as the web process.
- The scheduler publishes due campaigns and runs retention daily.
- Reverb is optional; without it the Vue client polls and does not claim
  realtime.
- `php artisan notifications:prune --dry-run` is the safe first retention check.
- `php artisan notifications:campaigns` publishes due campaigns and completes
  finished ones.

Deployment blockers that are configuration, not code:

1. Firebase project, service account, Android `google-services.json` and iOS
   APNs key/capability.
2. Reverb app id/key/secret, running server and TLS reverse proxy.
3. Real SMTP or transactional mail relay.
4. Approved WhatsApp template and destination-bound transport.
5. `ext-sodium` for the Firebase SDK, or a verified compatibility shim.

---

## 11. Residual limitations

- FCM, Reverb, SMTP and WhatsApp are not live-verified in this environment.
- `ext-sodium` is missing. `kreait/firebase-php` was installed with
  `--ignore-platform-req=ext-sodium` and `paragonie/sodium_compat` was added.
  This is not proof of a working FCM runtime.
- iOS push requires manual Xcode capability and an APNs key outside this
  repository.
- The browser uses Reverb, not Firebase web push. There is no separate browser
  push transport.
- Tenant/store/platform Reverb channels are authorised but personal
  notification content is intentionally broadcast only on the user's private
  channel.
- Campaign audience selection is all/tenant/user ids only; there is no invented
  plan or segment concept.
- Runtime UI was not manually exercised; verification is static analysis, unit
  tests and type-check.
- Full-project Flutter analysis/tests were not re-run after mobile `HEAD` moved
  from `13d4188` to `cc84165`.

---

## 12. Verification and unverified integrations

| Area | Evidence | Status |
| --- | --- | --- |
| Backend notification centre, preferences, campaigns, broadcast auth, devices, push logic, retention | Focused suite: 128 tests / 1080 assertions | Verified in test environment |
| Full backend suite | 752 tests / 4043 assertions on 2026-11-09 | Verified in test environment |
| Email templates, delivery monitoring, unsubscribe/consent | Included in the focused suite (`EmailTemplateAdminTest` 9 tests, `NotificationDeliveryMonitorTest` 8 tests, `NotificationUnsubscribeTest` 6 tests) | Verified in test environment |
| Vue type-check | `npm run type-check` | Passed |
| Vue unit tests | `npm test`; 27 tests | Passed |
| Flutter notification analysis | Targeted `flutter analyze` | Passed |
| Flutter notification tests | Targeted `flutter test`; 6 tests | Passed at `13d4188` |
| Reverb live delivery | No server/key; `BROADCAST_CONNECTION=log` | **Not verified** |
| FCM live delivery | No Firebase project/credentials/device | **Not verified** |
| SMTP | Array mailer in tests; no live relay | **Not verified** |
| WhatsApp | Disabled; no approved template/transport | **Not verified** |
| iOS push | No APNs key/capability | **Not configured** |
| Browser/mobile runtime UI | Type-check, analysis and unit tests only | Not manually exercised |

An integration is only “done” when the code exists **and** the deployment has
been configured and exercised. Code alone is not proof.

---

## 13. Conclusion

The notification ecosystem is architecturally complete and internally
consistent: MySQL is authoritative, the central engine is the only writer, every
outbound attempt has a tracked lifecycle, platform administration is permission
gated and audited, marketing consent is opt-in with secure expiring unsubscribe
links, and the three clients share the same API and event catalogue.

It is **not** correct to describe push, realtime web delivery, email or WhatsApp
as operational in this environment. Those claims require the configuration and
live verification listed in section 10. Until then, the honest statement is:
implemented, covered by tests with fake transports, and ready for deployment
verification.
