# Real-time notification audit

**Status:** implementation audit, 9 November 2026
**Scope:** `backend/` (Laravel), `frontend/` (Vue 3), `mobile/` (Flutter)
**Authority:** MySQL is the notification store. Firebase Cloud Messaging (FCM)
is a delivery transport only. Laravel Reverb carries web realtime events.

> This document records what existed before the notification-platform work, what
> changed, and which external integrations are configured and verified. An
> integration is only marked **verified** when it was exercised against a real
> service in this environment. Code that is written but unconfigured is marked
> **implemented, unverified**.

---

## 1. Audit summary

| Area | Before | After | Runtime status |
| --- | --- | --- | --- |
| Notification storage | `notifications` table + `Notification` model | Extended with store, priority, related entity, scope, archived state and dedupe key | Verified by tests (SQLite test DB) |
| Central dispatch | `NotificationDispatcher` worked for email/WhatsApp | Extended for push, in-app enforcement, after-commit broadcast, priorities and campaign delivery | Verified by tests |
| Domain events | A handful of direct dispatches | Event catalogue with per-event category, severity, channels and preferences | Verified by tests |
| Email | Branded multilingual pipeline (commit `dea7467`) | Unchanged; now one channel of the unified preference catalogue | Verified by tests, SMTP not exercised |
| Push | None | FCM HTTP v1 behind `PushTransport`, device registry, retries, token revocation | Implemented, **unverified**: no Firebase project or credentials in this environment |
| Web realtime | None | Laravel Reverb + Echo private channels, Sanctum authorisation, polling fallback | Implemented, **unverified**: Reverb is not running and `BROADCAST_CONNECTION=log` |
| In-app history | Vue bell with polling; Flutter list | Shared Laravel history for both clients, read/unread/delete, pagination, filters | Verified by tests; clients compile |
| Web UI | Bell, dropdown, page | Bell, badge, dropdown, centre, list, item, filters, settings, platform campaign console | Type-check passes |
| Flutter UI | List and unread badge | Centre, detail, filters, push lifecycle, deep links, Android channels | Static analysis pending final run; Firebase unverified |
| Preferences | Email/WhatsApp switches | Email, push, in-app and WhatsApp across the full catalogue; mandatory security alerts | Verified by tests |
| Platform broadcasts | None | Draft/schedule/publish/cancel, audience ledger, delivery report, audit log | Verified by tests |
| Scheduled digests | Weekly/monthly report scheduling | Timezone-aware period bounds, push enabled through the catalogue | Verified by tests |
| Retention | None | Daily prune command, dry-run, device/token cleanup | Verified by tests |

---

## 2. Backend audit

### 2.1 Existing foundations retained

- `notifications` (UUID primary key), `notification_deliveries`, and the
  `Notification` / `NotificationDelivery` models already existed. They remain
  the single source of truth for inbox history and delivery attempts.
- `NotificationDispatcher` already applied per-user channel preferences and
  recorded skipped deliveries for unavailable transports.
- `NotificationEventCatalog` and `NotificationEvent` already described event
  keys, categories and mail templates.
- Branded multilingual email rendering was implemented previously and is not
  replaced here.

### 2.2 Gaps found

1. Notifications were not associated with a store, a priority, a related entity
   or a platform/tenant scope, so the UI could not filter or route reliably.
2. There was no push transport, device registry, token lifecycle or delivery
   retry policy.
3. No realtime broadcast layer existed; the Vue bell polled.
4. In-app preference opt-out was not enforced server-side.
5. No per-event team/security notifications or mandatory security category.
6. No platform broadcast module, audience ledger or delivery reporting.
7. No retention policy for notifications, deliveries or device tokens.
8. Report scheduling used the application timezone rather than the tenant's.
9. There was no single-notification endpoint for a mobile deep link.

### 2.3 Changes delivered

- Migrations extend notifications and add `notification_devices`,
  `notification_campaigns` and `notification_campaign_recipients`.
- `NotificationPriority`, `NotificationCampaignStatus` and
  `NotificationCampaignRecipientStatus` enums.
- `PushTransport` contract and `FirebasePushTransport` using
  `kreait/firebase-php` HTTP v1. Credentials come only from
  `FIREBASE_CREDENTIALS` / `FIREBASE_CREDENTIALS_JSON` (or a file path), never
  from a request or the repository.
- `SendNotificationPush` fans a notification out to every active device,
  deduplicates on retry, revokes permanently invalid tokens and retries
  transient failures with backoff.
- `NotificationDeviceController` exposes list/register/update/delete/unregister,
  scoped to the authenticated account.
- `NotificationCreated` and `NotificationStateChanged` events broadcast after
  commit on private personal, tenant, store and platform-administrator channels.
- `NotificationCampaignService`, `PublishNotificationCampaign` and
  `DeliverCampaignNotification` implement platform broadcasts with a per-user
  ledger, consent checks, dedupe keys and batched delivery.
- `notifications:campaigns` publishes due campaigns every minute;
  `notifications:prune` applies retention daily.
- `ScheduledReportService` now computes period bounds in the workspace timezone.
- Profile password changes, team membership/role changes and role permission
  changes now produce notifications through events/services, not controllers.
- `GET /api/v1/notifications/{id}` added for deep links and the Flutter detail
  screen.

### 2.4 Backend verification

- Focused notification suite: **37 tests / 134 assertions passed**
  (`NotificationCenterTest`, `NotificationPreferenceTest`,
  `NotificationCampaignTest`, `NotificationBroadcastTest`,
  `NotificationRetentionTest`).
- Full backend suite result is recorded in the implementation report and the
  platform document.
- Pint formatting is run before commit.

---

## 3. Vue frontend audit

### 3.1 Before

- `NotificationBell`, `NotificationDropdown`, `NotificationItem` and a
  `/notifications` page existed.
- State lived in `notificationStore` and refreshed by polling.
- Preferences covered email/WhatsApp only.
- No platform broadcast screen existed.

### 3.2 After

- `src/lib/echo.ts` creates Reverb/Echo lazily, authorises private channels with
  the current Sanctum bearer token, and reconnects with backoff.
- `notificationStore` subscribes to:
  - `App.Models.User.{id}` for personal notifications and read-state changes;
  - `tenant.{id}` for workspace notifications;
  - `store.{id}` where a store is active;
  - `platform.admins` only for platform operators.
- A 60-second poll remains as a safety net when Reverb is unavailable or the
  environment key is empty. The UI does not pretend realtime is active when it
  is not.
- Components: `NotificationBell`, `UnreadCountBadge`, `NotificationDropdown`,
  `NotificationCenter`, `NotificationList`, `NotificationItem`,
  `NotificationFilters`, `NotificationSettings`.
- `NotificationsPage` and settings `NotificationsPage` are thin route shells
  around the shared centre/settings components.
- Navigation is centralised in `src/utils/notificationNavigation.ts`; external
  or malformed action URLs are rejected.
- EN/AR copy, RTL, light/dark and merchant/platform variants are covered.
- Platform campaign administration is a platform-only page at
  `/platform/notification-campaigns` with list, filters, create/edit,
  audience count, preview, schedule, publish/cancel/delete and a delivery
  report including recent failures.
- `vue-tsc --build` passes.

### 3.3 Verified / unverified

- Type-check: **verified**.
- Reverb live delivery: **implemented, unverified**. The backend currently has
  `BROADCAST_CONNECTION=log` and no Reverb app key in `.env`.
- Firebase web push: **not implemented**. The deployment has no browser push
  credentials or service worker contract; Reverb is the supported web channel.
  The settings screen disables push when the server reports it unavailable.

---

## 4. Flutter audit

### 4.1 Before

- Notification list, unread count provider and status filter existed.
- No detail screen, category/severity filters or deep-link handling.
- No Firebase dependency, token registration, local notification channels or
  foreground/background presentation.

### 4.2 After

- `firebase_core`, `firebase_messaging` and `flutter_local_notifications`
  dependencies added.
- `PushService`:
  - initialises Firebase only when all `DUKAVO_FIREBASE_*` dart-defines are
    present and the platform is supported;
  - requests permission, handles denial without breaking the app;
  - registers and refreshes the FCM token after authentication;
  - revokes the token before logout;
  - stores a random installation id in `SharedPreferences` so a rotated token
    replaces the same device row;
  - presents foreground messages locally and deduplicates by notification id;
  - opens the tap target from `action_url`, allowing only same-origin Dukavo
    links and in-app paths;
  - marks a tapped notification read through the Laravel API.
- Android channels: `orders`, `inventory`, `messages`, `ai`, `security`,
  `reports`, `announcements`; `POST_NOTIFICATIONS` permission and default
  Firebase icon/channel metadata added.
- iOS `UIBackgroundModes` includes `remote-notification`.
- Notification centre now has status, category and severity filters; tapping
  opens a detail screen backed by `GET /notifications/{id}`; read/unread/delete
  update the list and badge.
- EN/AR keys were added to both ARB files.

### 4.3 Verified / unverified

- `flutter analyze`: recorded in the implementation report.
- Firebase token registration, foreground/background delivery and tap routing:
  **implemented, unverified** because no Firebase project, credentials,
  `google-services.json` or APNs configuration exist in this environment.
- A release build must supply the dart-defines and (for iOS) enable the Push
  Notifications capability in Xcode as documented in
  `DUKAVO_NOTIFICATION_PLATFORM.md`.

---

## 5. External integration matrix

| Integration | Code | Configuration in repo | Verified live |
| --- | --- | --- | --- |
| MySQL notification history | Yes | Existing DB connection | Yes (tests; target DB not exercised) |
| Laravel queue | Yes | `QUEUE_CONNECTION=database` | Yes (sync test queue; worker not exercised) |
| Reverb websockets | Yes | Env names documented, no key | **No** |
| FCM HTTP v1 | Yes | Env names documented, no credentials | **No** |
| SMTP email | Existing | Env-driven | Email tests pass; SMTP not exercised |
| WhatsApp | Existing conversation channel | Not a business notification transport | Marked unavailable by `ChannelAvailability` |
| Scheduler | Yes | `routes/console.php` | Commands tested; production cron not exercised |

---

## 6. Isolation and security findings

- Personal notification endpoints scope every query through
  `$request->user()->notifications()`; foreign ids return 404.
- Device endpoints scope through the authenticated user and revoke a token when
  another account claims it.
- Campaign routes require a platform operator **and**
  `notifications.view` / `notifications.manage`; merchants never reach them.
- Private channel authorisation verifies the authenticated user, tenant
  membership and platform role before returning a channel token.
- Push payloads contain only ids, category, severity, priority and an action
  path — no customer names, totals, tokens or credentials.
- Firebase credentials are read from environment/config and never serialised
  into API resources or frontend bundles.
- In-app opt-out creates the authoritative row with `archived_at` set, so the
  bell stays quiet while audit and outbound channels still have a record.
- `security_alerts` is mandatory on every channel and forced on by the backend.

---

## 7. Remaining operational work

1. Configure `BROADCAST_CONNECTION=reverb`, Reverb app credentials and a running
   `php artisan reverb:start` process, then verify a live browser event.
2. Configure a Firebase project, service account, Android/iOS apps and
   `DUKAVO_FIREBASE_*` dart-defines, then verify token registration and real
   delivery on a device.
3. Run a queue worker for `notifications`, `notifications-push` and
   `notifications-campaigns`.
4. Add the scheduler entry for `php artisan schedule:run` in production.
5. Enable the iOS Push Notifications capability and upload an APNs key in
   Firebase.
6. Exercise SMTP and WhatsApp transports if the deployment intends to use them.
