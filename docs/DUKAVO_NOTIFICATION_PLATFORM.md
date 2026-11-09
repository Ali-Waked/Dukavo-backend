# Dukavo notification platform

**Status:** implementation and integration reference, 9 November 2026
**Owners:** backend (`backend/`), web (`frontend/`), mobile (`mobile/`)

> MySQL is the source of truth for every notification. Laravel Reverb carries
> real-time web events, and Firebase Cloud Messaging (FCM) is a push delivery
> transport only. No product feature reads notification state back from
> Firebase, and there is no Firebase Realtime Database in this design.
>
> **Verification caveat:** the FCM, Reverb, SMTP and WhatsApp transports are
> implemented and covered by tests with fake transports, but they are **not
> verified against live services in this environment**. See
> [Verification status](#verification-status) before describing any of them as
> operational.

---

## 1. What the platform does

One notification engine serves all three clients:

- **In-app history** in MySQL, shared by the Vue bell/centre and the Flutter
  notification centre.
- **Push** through FCM to every active device of an account.
- **Email** through the existing branded multilingual mail pipeline.
- **WhatsApp** where an approved template and transport exist; otherwise the
  channel is reported unavailable and deliveries are recorded as skipped.
- **Web realtime** through Laravel Reverb private channels, with a polling
  safety net when Reverb is not configured.
- **Preferences** per channel and event, with mandatory security notices and
  opt-in marketing consent.
- **Platform broadcasts** for Dukavo staff only, with scheduling, a recipient
  ledger, delivery reporting and audit logs.

Controllers do not build notifications. A domain service raises a
`NotificationIntent` after its own state change commits, and the central
`NotificationDispatcher` decides recipients, preferences, stored copy,
deliveries and transport jobs.

---

## 2. Architecture

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

Platform campaigns use the same notification and delivery tables but add a
`notification_campaigns` row and one `notification_campaign_recipients` row per
resolved user. Campaign delivery jobs are batched on the
`notifications-campaigns` queue and rate-limited.

---

## 3. Backend components

| Component | Responsibility |
| --- | --- |
| `NotificationEventCatalog` | The complete event catalogue: scope, category, severity, channels, preference key, required permission and EN/AR copy. Unknown keys are rejected. |
| `NotificationIntent` | Immutable description of what happened: event key, tenant/store, data, action URL, severity/priority overrides, related model, dedupe key. |
| `NotificationRecipientResolver` | Resolves the users who should receive an event, respecting tenant/store membership and event permissions. |
| `NotificationDispatcher` | Single dispatch entry point. Enforces preferences, claims idempotency, writes the notification and delivery rows, and defers broadcasts/jobs until commit. |
| `ChannelAvailability` | Reports whether mail/push/WhatsApp can actually send. Used by the dispatcher, campaign service and settings endpoint. |
| `NotificationCampaignService` | Platform broadcast lifecycle, audience resolution, copy preview and audit. |
| `SendNotificationEmail` / `SendNotificationPush` / `SendNotificationWhatsApp` | Per-delivery queue jobs. |
| `NotificationCreated` / `NotificationStateChanged` | After-commit Reverb events. |
| `notifications:campaigns` | Publishes due campaigns and completes finished ones every minute. |
| `notifications:prune` | Applies notification and device retention daily. |

### 3.1 Dispatch guarantees

1. The notification row and its delivery rows are written in one transaction.
2. Broadcasts and send jobs are dispatched with `DB::afterCommit`, so a
   rolled-back business transaction never produces a message.
3. A one-shot event is claimed once per recipient in
   `notification_event_keys`. Recurring events use a cooldown (for example,
   low stock is limited to one alert per inventory row per day and restock
   reminders to one per seven days).
4. A channel that cannot send is stored as `skipped` with a reason; no job is
   queued for it.
5. Push delivery rows are omitted entirely when Firebase is unavailable, so the
   table does not accumulate meaningless skips for every event.
6. In-app opt-out is enforced server-side by creating the authoritative row
   with `archived_at = now()`. The item is hidden from the bell but retained for
   audit and for any outbound channel the user still allows.

### 3.2 In-app vs outbound channels

In-app is not a `NotificationChannel` enum case: the notification row *is* the
in-app delivery, and there is no provider attempt to track. Outbound channels
are `mail`, `push` and `whatsapp`, with queue names `notifications-mail`,
`notifications-push` and `notifications-whatsapp`.

`mail` is stored under the preference key `email` for backwards compatibility
with the account settings screen. The other mapping is direct.

---

## 4. Data model

### 4.1 `notifications` (extended by `2026_11_08_130000`)

Existing columns include UUID primary key, `notifiable_type/id`, `tenant_id`,
`event_key`, `category`, `severity`, `title`, `body`, `action_url`, `data`,
`read_at`, `archived_at`, `created_at`, `updated_at`.

Added:

| Column | Purpose |
| --- | --- |
| `store_id` | Nullable store scope, FK with `nullOnDelete`. |
| `priority` | `low`, `normal`, `high`, `critical`; separate from severity. |
| `related_type`, `related_id` | Optional link to the real record (order, product, conversation, AI action, …). |
| `dedupe_key` | Optional per-campaign/per-report idempotency key. |

Indexes added: recipient + category + read state, recipient + scope + date,
store, and related entity. Existing recipient indexes remain.

### 4.2 `notification_deliveries`

One row per outbound channel attempt, with `status`, `attempts`, `error`,
`queued_at`, `sent_at`, `failed_at`, `provider_payload` and a unique
`(notification_id, channel)` constraint. The unique pair makes a retried
dispatch reuse the same attempt rather than creating a duplicate.

### 4.3 `notification_devices` (`2026_11_08_130001`)

| Column | Notes |
| --- | --- |
| `user_id` | FK, cascade on account deletion. |
| `device_id` | App-generated, stable across FCM token rotation. |
| `platform` | `android`, `ios`, `web`, `unknown`. |
| `token` | Opaque FCM registration token; never returned by the API. |
| `token_hash` | SHA-256, unique; the lookup key and duplicate guard. |
| `device_name`, `app_version`, `locale` | Display and localisation metadata. |
| `last_seen_at`, `revoked_at` | Active/retired lifecycle. |

A rotated token for the same `device_id` revokes the previous active row. A
token claimed by another account is reassigned only after the old row is
revoked, so a second-hand phone cannot keep receiving the previous account's
alerts.

### 4.4 `notification_campaigns` (`2026_11_08_130002`)

Campaign intent and progress: UUID, author, title/body, category, severity,
priority, action URL, selected channels, audience JSON snapshot, marketing flag,
lifecycle status, schedule/publish/cancel/complete timestamps, aggregate
counters, and a unique client idempotency key.

### 4.5 `notification_campaign_recipients` (`2026_11_08_130003`)

Per-recipient ledger: campaign, user, optional notification id, status
(`pending`, `queued`, `sent`, `skipped`, `failed`), error, and stage
timestamps. Unique `(campaign_id, user_id)` prevents duplicate queueing even if
an audience is resolved twice.

### 4.6 `notification_event_keys`

Existing idempotency ledger keyed by event + recipient (+ related scope). It
stores the last notification time and count, which implements one-shot and
cooldown semantics atomically under a row lock and unique constraint.

---

## 5. Event catalogue

The catalogue has 39 events. Channel lists are the event's declared intent;
actual delivery also depends on the recipient's preference, channel
availability and destination (verified email, active device, approved WhatsApp
template).

| Event key | Scope | Category | Severity | Declared channels | Preference | Permission |
| --- | --- | --- | --- | --- | --- | --- |
| `platform.waitlist.received` | platform | leads | info | push | — | — |
| `platform.contact.received` | platform | leads | info | push | — | — |
| `platform.tenant.onboarded` | platform | tenants | success | push | — | — |
| `tenant.order.created` | tenant | orders | info | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.paid` | tenant | orders | success | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.shipped` | tenant | orders | info | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.delivered` | tenant | orders | success | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.cancelled` | tenant | orders | warning | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.refunded` | tenant | orders | warning | mail, whatsapp, push | order_updates | orders.view |
| `tenant.order.status_changed` | tenant | orders | info | mail, whatsapp, push | order_updates | orders.view |
| `tenant.inventory.low_stock` | tenant | inventory | warning | mail, whatsapp, push | low_stock | inventory.manage |
| `tenant.inventory.out_of_stock` | tenant | inventory | critical | mail, whatsapp, push | low_stock | inventory.manage |
| `tenant.inventory.restock_reminder` | tenant | inventory | warning | mail, push | low_stock | inventory.manage |
| `tenant.inventory.adjusted` | tenant | inventory | info | mail, push | low_stock | inventory.manage |
| `tenant.conversation.message_received` | tenant | messages | info | mail, whatsapp, push | new_messages | conversations.view |
| `tenant.conversation.assigned` | tenant | messages | info | mail, whatsapp, push | conversation_assignments | conversations.view |
| `tenant.conversation.attention` | tenant | messages | warning | mail, push | new_messages | conversations.view |
| `tenant.message.failed` | tenant | messages | warning | mail, whatsapp, push | delivery_failures | conversations.view |
| `tenant.ai_action.awaiting_review` | tenant | automation | warning | mail, push | approvals | ai.approve |
| `tenant.ai_action.approved` | tenant | automation | success | mail, push | approvals | ai.approve |
| `tenant.ai_action.rejected` | tenant | automation | warning | mail, push | approvals | ai.approve |
| `tenant.ai_action.failed` | tenant | automation | critical | mail, push | approvals | ai.approve |
| `tenant.report.weekly` | tenant | reports | info | mail, push | weekly_summary | profit.view |
| `tenant.report.monthly` | tenant | reports | info | mail, push | weekly_summary | profit.view |
| `tenant.report.performance` | tenant | reports | info | mail, push | weekly_summary | profit.view |
| `tenant.announcement.product` | tenant | announcements | info | mail, push | product_news | dashboard.view |
| `tenant.announcement.release` | tenant | announcements | info | mail, push | product_news | dashboard.view |
| `tenant.announcement.maintenance` | tenant | announcements | warning | mail, push | — | dashboard.view |
| `platform.tenant.suspended` | platform | tenants | warning | push | — | — |
| `platform.tenant.reactivated` | platform | tenants | success | push | — | — |
| `tenant.approval.request` | tenant | approvals | warning | mail, whatsapp, push | approvals | ai.approve |
| `tenant.automation.triggered` | tenant | automation | info | push | — | automation.manage |
| `tenant.team.member_invited` | tenant | team | info | mail, push | team_activity | users.manage |
| `tenant.team.member_joined` | tenant | team | success | mail, push | team_activity | users.manage |
| `tenant.team.role_changed` | tenant | team | info | mail, push | team_activity | users.manage |
| `tenant.team.permission_changed` | tenant | team | warning | mail, push | team_activity | roles.manage |
| `tenant.team.member_removed` | tenant | team | warning | mail, push | team_activity | users.manage |
| `tenant.security.password_reset` | tenant | security | critical | mail, push | security_alerts | dashboard.view |
| `platform.broadcast` | tenant/platform | campaign category | campaign | campaign channels | campaign consent | — |

`platform.broadcast` is the event key used by campaign-generated notification
rows; it is not a catalogue entry that emitters call directly.

Copy is defined in EN and AR in the catalogue. An emitter supplies only data
placeholders; a missing translation is a development error, not a silently
empty message.

---

## 6. HTTP API

All routes are under `/api/v1` and require a Sanctum-authenticated, active
account. The notification centre and device routes are personal and are not
tenant-scoped. Campaign routes are platform-only and require an explicit
permission.

### 6.1 Notification centre

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/notifications` | Paginated list, newest first. Hidden (archived) rows excluded. |
| `GET` | `/notifications/unread-count` | `{ count: n }` for the bell. |
| `GET` | `/notifications/{id}` | One owned notification; used by mobile deep links. |
| `POST` | `/notifications/read-all` | Marks all visible unread rows read; returns `{ updated: n }`. |
| `POST` | `/notifications/{id}/read` | Mark one read; returns the resource. |
| `POST` | `/notifications/{id}/unread` | Mark one unread; returns the resource. |
| `DELETE` | `/notifications/{id}` | Delete an owned notification; `204`. |

List filters:

| Query | Values | Default |
| --- | --- | --- |
| `status` | `all`, `unread`, `read` | `all` |
| `category` | event category, e.g. `orders`, `inventory`, `messages` | all |
| `severity` | `info`, `success`, `warning`, `critical` | all |
| `scope` | `platform`, `tenant` | all |
| `per_page` | `1`–`100` | `20` |
| `page` | page number | `1` |

A foreign or unknown id returns **404**, never 403, so another account's
notification existence is not disclosed.

Resource shape:

```json
{
  "id": "8c1e…",
  "event_key": "tenant.order.created",
  "category": "orders",
  "severity": "info",
  "priority": "normal",
  "scope": "tenant",
  "tenant_id": 12,
  "store_id": 4,
  "related_type": "order",
  "related_id": 1042,
  "title": "New order ORD-1042",
  "body": "Sara placed an order worth 120.00 USD.",
  "action_url": "/orders/1042",
  "params": { "order_id": 1042 },
  "read": false,
  "read_at": null,
  "created_at": "2026-11-09T08:00:00+00:00"
}
```

Read, unread, delete and read-all also broadcast `notification.state` on the
owner's private channel after commit.

### 6.2 Push devices

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/profile/devices` | Active devices for the caller. Token is never returned. |
| `POST` | `/profile/devices` | Register/upsert an installation. Throttled by `notification-devices` (30/min per account). |
| `POST` | `/profile/devices/unregister` | Revoke by token at logout; idempotent `204`. |
| `PATCH` | `/profile/devices/{id}` | Update device metadata for an owned device. |
| `DELETE` | `/profile/devices/{id}` | Revoke an owned device; `204`. |

Registration body:

```json
{
  "token": "<fcm-registration-token>",
  "device_id": "<stable installation id>",
  "platform": "android",
  "device_name": "Android device",
  "app_version": "1.0.0",
  "locale": "en"
}
```

The same token always maps to one `token_hash` row. A rotated token for the
same `device_id` revokes the old row. Registering a token owned by another
account reassigns it after revocation, preventing cross-account delivery.

### 6.3 Notification preferences

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/profile/notifications/channels` | Channel availability (`email`, `push`, `in_app`, `whatsapp`) with a reason when unavailable. |
| `GET` | `/profile/notifications` | Resolved preference set for every channel/event. |
| `PUT` | `/profile/notifications` | Update provided switches. Unknown keys are dropped by validation. |

The preference routes live in the profile group with optional tenant resolution;
the notification centre routes are a separate `/notifications` group outside the
tenant group. Both are personal to the account.

### 6.4 Platform campaigns

Every route is behind `platform` middleware **and** `notifications.view` or
`notifications.manage`. Merchant tokens cannot reach them.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| `GET` | `/platform/notification-campaigns` | view | List/filter/search, paginated. |
| `POST` | `/platform/notification-campaigns` | manage | Create a draft. |
| `POST` | `/platform/notification-campaigns/preview` | view | Render `{name}`/`{workspace}` against a real user. |
| `POST` | `/platform/notification-campaigns/audience-count` | view | Count the current audience. |
| `GET` | `/platform/notification-campaigns/{id}` | view | Campaign detail. |
| `PATCH` | `/platform/notification-campaigns/{id}` | manage | Edit a draft/scheduled campaign. |
| `DELETE` | `/platform/notification-campaigns/{id}` | manage | Delete a draft/scheduled campaign. |
| `POST` | `/platform/notification-campaigns/{id}/schedule` | manage | Set a future publish time. |
| `POST` | `/platform/notification-campaigns/{id}/publish` | manage | Start publishing now. |
| `POST` | `/platform/notification-campaigns/{id}/cancel` | manage | Cancel before completion. |
| `GET` | `/platform/notification-campaigns/{id}/recipients` | view | Per-recipient ledger, filterable by status. |
| `GET` | `/platform/notification-campaigns/{id}/stats` | view | Live status counts and recent failures. |

Create body:

```json
{
  "title": "Scheduled maintenance on Sunday",
  "body": "Hi {name}, {workspace} will be read-only from 02:00 to 03:00 UTC.",
  "category": "announcements",
  "severity": "warning",
  "priority": "high",
  "action_url": "/announcements/maintenance",
  "channels": ["in_app", "push", "mail"],
  "audience": {
    "type": "tenants",
    "tenant_ids": [12, 18],
    "exclude_user_ids": [91]
  },
  "is_marketing": false,
  "scheduled_at": "2026-11-15T02:00:00Z",
  "idempotency_key": "maintenance-2026-11-15"
}
```

Lifecycle refusals are `409` with a machine-readable `code` (for example
`campaign_not_editable`, `campaign_cancelled`, `campaign_schedule_past`).
Ordinary validation failures remain `422`.

Audience types:

- `all` — every active user with a tenant who is not a platform-only operator.
- `tenants` — users belonging to any listed tenant.
- `users` — explicitly listed user ids.
- `exclude_user_ids` — removed from any audience type.

Platform staff are excluded from campaign audiences even for `all`.

---

## 7. Reverb and the Vue client

### 7.1 Private channels

| Channel | Authorised when |
| --- | --- |
| `App.Models.User.{userId}` | Authenticated user id equals the channel id. |
| `tenant.{tenantId}` | User belongs to the tenant. |
| `store.{storeId}` | Store exists and the user belongs to its tenant. |
| `platform.admins` | User is a platform operator. |

Broadcast authentication is registered on the API stack with
`['api', 'auth:sanctum', 'active']`, so the SPA authorises with the same bearer
token as its API calls. No channel trusts a tenant/store id from query input
without a server-side membership check.

`NotificationCreated` broadcasts as `notification.created` and
`NotificationStateChanged` as `notification.state`. The created payload carries
the notification plus the fresh unread count; the state payload carries only
the action, ids and count. Current notification rows are addressed to one user,
so content is published only on that user's private channel. Tenant, store and
platform channels are authorised and available for non-personal events, but
personal notification content must never be broadcast to them.

### 7.2 Vue client

- `src/lib/echo.ts` creates Echo lazily. An empty `VITE_REVERB_APP_KEY` disables
  the socket; nothing pretends realtime is active.
- A dynamic authorizer reads the current access token for every subscription,
  so a rotated token does not leave stale authorization behind.
- The store subscribes to personal, tenant, store and platform channels as
  appropriate and re-syncs the unread count after a reconnect.
- A 60-second unread-count poll remains as a safety net for a dropped socket, a
  suspended tab, or an installation without Reverb.
- Required components: `NotificationBell`, `UnreadCountBadge`,
  `NotificationDropdown`, `NotificationCenter`, `NotificationList`,
  `NotificationItem`, `NotificationFilters`, `NotificationSettings`.
- Navigation is centralised in `src/utils/notificationNavigation.ts` and
  accepts only same-origin absolute URLs or in-app paths; protocol-relative,
  `javascript:` and external URLs are refused.

Realtime must be treated as an accelerator. The list is always refetched from
Laravel; a browser event cannot create history that MySQL does not have.

### 7.3 Reverb setup

```bash
# backend/.env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local-app
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# frontend/.env
VITE_REVERB_APP_KEY=local-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http

php artisan reverb:start
```

In production use TLS (`REVERB_SCHEME=https`, `VITE_REVERB_SCHEME=https`), a
real app secret, and a reverse proxy that forwards WebSocket upgrades. Run
Reverb as a supervised process, not inside a request worker.

---

## 8. Push (FCM)

### 8.1 Configuration

FCM uses `kreait/firebase-php` over HTTP v1 behind the `PushTransport`
contract. The engine never imports a Firebase class; tests bind a fake
transport.

Required environment:

```dotenv
DUKAVO_NOTIFICATION_PUSH=true
FIREBASE_PROJECT_ID=your-project-id

# Either a mounted service-account JSON file…
FIREBASE_CREDENTIALS=/run/secrets/firebase-service-account.json

# …or the document itself, raw or base64. Never commit either.
FIREBASE_CREDENTIALS_JSON=
FIREBASE_HTTP_TIMEOUT=10
```

A path in `FIREBASE_CREDENTIALS` wins when both are present. Missing project or
credentials makes `ChannelAvailability` report `push_credentials_missing`; the
settings screen disables the push switches and no push delivery rows are
created.

> **Dependency note:** `kreait/firebase-php` v8.6 requires `ext-sodium`. This
> environment does not provide it; the package was installed with
> `--ignore-platform-req=ext-sodium` and `paragonie/sodium_compat` was added.
> That workaround is not proof of a working FCM runtime. Install `ext-sodium`
> in production or verify the compatibility shim with a real send.

### 8.2 Delivery lifecycle

1. Flutter registers its token after authentication:
   `POST /devices` with token, stable installation id and metadata.
2. `SendNotificationPush` loads the user's active devices at send time, so a
   phone registered after queueing still receives the alert and a logged-out
   phone is not resurrected.
3. A permanent Firebase rejection (`UNREGISTERED`, not found, invalid
   argument) revokes that device row. A transient failure rethrows so the queue
   retries with the configured backoff (default 3 tries, 60s then 300s).
4. Successful device ids are stored in the delivery payload. A retry skips
   devices that already accepted the message, so no phone gets a duplicate.
5. When some devices succeed and some fail, successes are persisted before the
   job retries the remainder.
6. Logout calls `POST /devices/unregister` with the token; the endpoint is
   idempotent and scoped to the caller.

Android channels are created by the Flutter app and addressed by the backend
through `config/firebase.php`. Ids must match exactly:

| Category | Android channel id |
| --- | --- |
| orders | `orders` |
| inventory | `inventory` |
| messages | `messages` |
| ai / automation / approvals | `ai` |
| security / team | `security` |
| reports | `reports` |
| announcements / leads / tenants / default | `announcements` |

The most important channel intentionally if a category is unknown is
`announcements`; it is also the manifest default.

### 8.3 Push payload

The notification shade shows a short title/body. The data payload contains only
routing metadata: `notification_id`, `event_key`, `category`, `priority`,
`severity`, optional `action_url`, `tenant_id`, `store_id` and `type`.
Customer names, totals, credentials and tokens are not included. Tapping fetches
the authoritative record from Laravel.

### 8.4 Current verification

Push is **implemented, not live-verified**:

- no Firebase project, service account, `google-services.json` or APNs key in
  this environment;
- no physical device or emulator delivery test;
- the transport is covered by fake-transport tests only.

Do not tell a merchant push is working until a real token receives a real
message.

---

## 9. Flutter client

### 9.1 Firebase configuration

Firebase values are compiled in with `--dart-define`; no
`google-services.json` or `GoogleService-Info.plist` is committed.

```bash
flutter build apk \
  --dart-define=DUKAVO_FIREBASE_PROJECT_ID=your-project-id \
  --dart-define=DUKAVO_FIREBASE_API_KEY=... \
  --dart-define=DUKAVO_FIREBASE_APP_ID=... \
  --dart-define=DUKAVO_FIREBASE_MESSAGING_SENDER_ID=... \
  --dart-define=DUKAVO_FIREBASE_STORAGE_BUCKET=... \
  --dart-define=DUKAVO_FIREBASE_IOS_BUNDLE_ID=com.dukavo.mobile
```

`AppConfig.firebaseConfigured` requires project id, API key, app id and sender
id. If any is missing, or the platform is unsupported, `PushService` reports
`unconfigured` and the rest of the app runs normally. Initialisation failures
are caught and logged; they do not crash boot.

### 9.2 Lifecycle

- Firebase initialises after the first frame, not during the root build.
- Permission is requested after initialisation. Denial is a supported state
  (`PushAvailability.denied`), not an error.
- The token is registered after authentication and on token refresh; the same
  random installation id is persisted so token rotation replaces the old device
  row instead of adding a second one.
- Logout revokes the token before the session is cleared.
- Foreground messages are presented with `flutter_local_notifications`, keyed by
  notification id and deduplicated in memory.
- Background/terminated taps and initial messages route from `action_url` or,
  when absent, to the notification detail screen.
- A tapped notification is marked read through Laravel.
- Deep link resolution accepts only same-origin Dukavo URLs and in-app paths.
  Protocol-relative URLs (`//host`), other hosts and non-http schemes fall back
  to `/notifications/{id}` or `/notifications`.

### 9.3 Screens

- `NotificationsScreen`: status, category and severity filters; pagination;
  mark read/unread; delete; unread badge; read-only/empty/error states.
- `NotificationDetailScreen`: fetches `GET /notifications/{id}` on a cold deep
  link, marks the item read once, supports read/unread/delete and “open related”.
- `SettingsScreen` notification section: email, push, in-app and WhatsApp
  switches across the event catalogue. `security_alerts` is displayed as
  mandatory and locked; unavailable channels are disabled with a reason.
- EN/AR copy and light/dark themes use the existing design tokens.

### 9.4 Android/iOS project files

- `AndroidManifest.xml`: `POST_NOTIFICATIONS`, Firebase default icon and default
  channel (`announcements`). The FCM service/receiver are supplied by the
  plugin manifest merge.
- `Info.plist`: `UIBackgroundModes` includes `remote-notification`.
- iOS still requires the **Push Notifications** capability and an APNs key in
  Firebase before it can receive anything. That is deployment configuration,
  not code in this repository.

---

## 10. Preferences and consent

Channels: `email`, `push`, `in_app`, `whatsapp`.
Events: `order_updates`, `low_stock`, `new_messages`,
`conversation_assignments`, `delivery_failures`, `approvals`,
`weekly_summary`, `product_news`, `team_activity`, `security_alerts`.
WhatsApp exposes only events with a realistic business template:
order_updates, low_stock, new_messages, conversation_assignments,
delivery_failures.

Defaults:

| Event | Email | Push | In-app | WhatsApp |
| --- | --- | --- | --- | --- |
| order_updates | on | on | on | on |
| low_stock | on | on | on | on |
| new_messages | off | on | on | on |
| conversation_assignments | role-aware (off by default) | on | on | off |
| delivery_failures | role-aware (off by default) | on | on | off |
| approvals | role-aware (off by default) | on | on | — |
| weekly_summary | on | on | on | — |
| product_news | **off** | **off** | **off** | — |
| team_activity | off | on | on | — |
| security_alerts | **forced on** | **forced on** | **forced on** | — |

Role-aware defaults turn conversation/approval switches on only when the active
workspace proves the matching permission (`conversations.view`, `ai.approve`),
so a merchant is not opted into work they cannot do. An explicit stored choice
always wins over a default.

`security_alerts` is mandatory on every channel: the backend forces it on even
if a stored payload says otherwise, and the clients render it locked. It is an
account-security contract, not marketing.

`product_news` is the marketing consent switch on each channel and defaults off
everywhere, including in-app. Campaigns with `is_marketing = true` are skipped
for any recipient without the matching channel consent. A campaign to `all` is
therefore not a licence to market to everyone.

Push delivery of catalogue events is allowed centrally, but actual push
delivery still requires the per-event `push` preference and an active device.
When Firebase is globally unconfigured, push delivery rows are omitted rather
than recorded as permanent skips.

---

## 11. Platform campaigns

### 11.1 Lifecycle

```text
draft ──schedule──► scheduled ──┐
  │                              ├── publish ──► publishing ──► published ──► completed
  └──────────── publish ─────────┘                     │
                                                       └── cancel ──► cancelled
```

- `draft` and `scheduled` are editable and deletable.
- Publishing flips to `publishing` before dispatching the audience job, so a
  double-clicked publish does not create two ledgers.
- `PublishNotificationCampaign` streams the audience with `chunkById`, creates
  one batch per chunk on `notifications-campaigns`, and marks the campaign
  `published` when the audience has been queued.
- `DeliverCampaignNotification` checks cancellation, writes the recipient
  ledger row first, enforces consent and channel availability, then creates the
  notification and delivery jobs after commit.
- `notifications:campaigns` publishes due scheduled campaigns every minute and
  completes campaigns whose ledger has no work left.
- A cancelled campaign is terminal. A completed/published campaign cannot be
  edited or deleted.

### 11.2 Deduplication and pacing

- Campaign recipient unique index `(campaign_id, user_id)`.
- Notification `dedupe_key = campaign:{uuid}:user:{id}` and `firstOrCreate`, so
  a retried job reuses the same authoritative row.
- Delivery unique `(notification_id, channel)`.
- `idempotency_key` on campaign create stops a double-submitted compose form
  from producing two campaigns.
- Campaign jobs use the `notification-campaigns` rate limiter
  (`DUKAVO_NOTIFICATION_BROADCAST_PER_MINUTE`, default 600) and audience
  chunking (`DUKAVO_NOTIFICATION_BROADCAST_CHUNK`, default 250).
- Audit actions record create, update, schedule, publish and cancel against the
  acting operator.

### 11.3 Delivery reporting

`/stats` returns the campaign status, aggregate counters and a live per-status
breakdown plus the ten most recent failures. `/recipients?status=failed` exposes
the individual ledger rows with the error. `skipped` is not a failure: it means
the platform deliberately did not send because there was no consent, no active
device, or the channel was unavailable.

### 11.4 Access control

Only platform operators with `notifications.view` or `notifications.manage` can
reach the campaign API or the `/platform/notification-campaigns` Vue page.
Merchants have no route into the group. Platform staff are excluded from
campaign audiences, and campaigns never use merchant tenant credentials.

---

## 12. Scheduled reports and retention

### 12.1 Reports

`email:report {weekly|monthly|performance}` iterates active tenants, runs each
inside that tenant's context, builds the period using the tenant's timezone and
dispatches through the same notification engine (`tenant.report.*`). A report
carries only values the platform actually stores: net revenue, gross profit,
orders, units, refunds, average order and cost coverage. Missing values remain
empty rather than becoming a misleading zero.

Period bounds:

- weekly: rolling seven days ending at the tenant's “today” start.
- monthly: the previous completed calendar month.
- performance: rolling 30 days.

Dedupe key is event + tenant + hash of the computed period, so re-running the
command for the same period is harmless.

Scheduler entries are in `routes/console.php`; reports run weekly on Monday at
07:00, monthly/performance on the first at 07:30/08:00. The scheduler process
uses the application timezone; the report data uses the tenant timezone.

### 12.2 Retention

`notifications:prune` runs daily at 03:30:

- archived rows older than `DUKAVO_NOTIFICATION_RETENTION_ARCHIVED_DAYS`
  (default 30) are deleted;
- read rows older than `DUKAVO_NOTIFICATION_RETENTION_READ_DAYS` (default 180)
  are deleted;
- revoked devices older than `DUKAVO_NOTIFICATION_DEVICE_RETENTION_DAYS`
  (default 180) are deleted;
- active devices not seen within that window are revoked, not deleted, so the
  reason they stopped receiving alerts remains explainable.

Unread rows are never deleted by age. Use `--dry-run` to inspect counts first.

---

## 13. Security and isolation

- **Recipient scoping:** every personal notification query goes through
  `$request->user()->notifications()`. Foreign ids resolve to 404.
- **Tenant/store isolation:** recipient resolution and event permissions are
  server-side. Broadcast authorization re-checks tenant membership and platform
  role; a client-supplied id is never trusted.
- **Campaign authorization:** platform middleware plus explicit permission;
  merchant access is impossible even if a merchant guesses the URL.
- **Device ownership:** device list/update/delete are scoped to the caller. A
  token claimed by another account is revoked from the previous owner.
- **Payload minimisation:** push payloads carry ids and routing metadata, not
  customer data, totals, credentials or tokens. Reverb state changes carry ids
  and counts only.
- **Credential handling:** FCM credentials are read from environment/config and
  never serialised into API resources or frontend bundles. `.env.example` uses
  empty placeholders only.
- **Opt-out enforcement:** in-app opt-out hides the row via `archived_at`; the
  backend still retains the record, and outbound channels obey the same stored
  preference. Marketing consent is checked per channel at send time.
- **Mandatory security:** `security_alerts` is forced on by the backend.
- **Rate limiting:** device registration is limited per account; campaign jobs
  are limited and batched; auth/campaign routes are behind normal API and
  permission middleware.
- **Audit:** campaign create/update/schedule/publish/cancel are recorded through
  `AuditLogger`. Sensitive payloads are not logged.
- **Idempotency:** event claims, campaign recipient uniqueness, delivery
  uniqueness, campaign notification dedupe keys and idempotency keys.

---

## 14. Operations and deployment

### 14.1 Required processes

```bash
# Apply schema
php artisan migrate --force

# Channel workers (separate queues so one provider cannot starve another)
php artisan queue:work --queue=notifications-mail,notifications-push,notifications-whatsapp --tries=3

# Campaign workers/batches
php artisan queue:work --queue=notifications-campaigns --tries=3

# Scheduler (cron every minute)
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1

# Reverb (when BROADCAST_CONNECTION=reverb)
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Queue workers need `DUKAVO_NOTIFICATION_PUSH`, Firebase credentials and
`FIREBASE_PROJECT_ID` in their environment, not only in the web process.

### 14.2 Deployment checklist

1. Install PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`,
   `bcmath`, and **`sodium`** for the Firebase SDK (see the dependency note).
2. Run migrations; verify the new indexes/FKs exist in MySQL.
3. Set `QUEUE_CONNECTION` and run workers under a supervisor.
4. Add the scheduler cron entry.
5. Configure Reverb credentials, run the server, set the `VITE_REVERB_*`
   build variables, and verify a live event in a browser.
6. Configure FCM project/credentials, build Flutter with the
   `DUKAVO_FIREBASE_*` defines, install on a device and verify token
   registration plus a real foreground/background/tap delivery.
7. Enable iOS Push Notifications capability and upload an APNs key.
8. If email is used, configure SMTP and verify a branded message; mail defaults
   to `log` and does not deliver externally.
9. If WhatsApp is used, configure an approved template and a destination-bound
   transport; otherwise leave it disabled and expect `skipped` deliveries.
10. Set retention and broadcast pacing values for the expected volume.
11. Never commit `.env`, service-account JSON, APNs keys or `google-services`
   files.

### 14.3 Health checks

- `php artisan notifications:prune --dry-run` should report plausible counts.
- `php artisan notifications:campaigns` should publish due campaigns and
  complete finished ones.
- `php artisan queue:failed` should be empty after a deploy smoke test.
- `GET /api/v1/notifications/channels` should show `push.available = true` only
  when FCM is actually configured.
- A browser with Reverb configured should show a connected socket; without a
  key it should keep working through polling without claiming realtime.

---

## 15. Verification status

| Area | Evidence | Status |
| --- | --- | --- |
| Backend notification centre, preferences, campaigns, broadcast auth, retention | Focused suite: 37 tests / 134 assertions passed | Verified in test environment |
| Full backend suite | See the implementation report; run with a raised PHP memory limit (`php -d memory_limit=1G artisan test`) because the route file exceeds the default 128 MB on this machine | Recorded per release |
| Vue type-check | `npm run type-check` | Passed |
| Vue navigation safety | `npm test` (`tests/notificationNavigation.test.ts`) | Passed |
| Flutter static analysis | `flutter analyze` | Passed |
| Flutter notification parsing/deep links | `flutter test test/features/notifications/notification_test.dart` | Passed |
| Reverb live delivery | No server/key configured; `BROADCAST_CONNECTION=log` | **Not verified** |
| FCM live delivery | No Firebase project/credentials/device | **Not verified** |
| SMTP | Tests use array mailer; no live SMTP | **Not verified** |
| WhatsApp | Disabled by configuration; no approved template/transport | **Not verified** |
| iOS push | No APNs key/capability in this environment | **Not configured** |
| Browser/mobile runtime UI | Static analysis and unit tests only | Not manually exercised |

An integration is only “done” when the code exists **and** the deployment has
been configured and exercised. Code alone is not proof.

---

## 16. Troubleshooting

| Symptom | Likely cause | Check |
| --- | --- | --- |
| Bell never updates live | `VITE_REVERB_APP_KEY` empty, Reverb not running, or `BROADCAST_CONNECTION` not `reverb` | Browser network/WS tab; backend `.env`; `php artisan reverb:start`; polling should still work. |
| WebSocket connects but channel auth fails | Bearer token missing/expired, user not in tenant, or platform role missing | `POST /broadcasting/auth` response; Sanctum token; tenant membership. |
| Push switches disabled | `push.enabled` false, project or credentials missing | `GET /notifications/channels` reason (`push_disabled`, `push_credentials_missing`). |
| Device registers but gets no push | Token row revoked, wrong Firebase project, app built without dart-defines, or FCM disabled on worker | `notification_devices` row, worker env, Firebase console, device logs. |
| Duplicate push on one phone | Token rotated without a stable `device_id`, or two active rows | `notification_devices` for the user; ensure Flutter sends the persisted installation id. |
| Android notification uses the wrong sound/importance | Backend category maps to a channel id the app did not create | Compare `config/firebase.php` ids with `push_service.dart` and reinstall the app. |
| Marketing campaign reaches nobody | Expected when `product_news` is off; consent is checked per channel | Campaign `/stats` shows `skipped`; inspect the recipient ledger error `no_channel_allowed`. |
| Campaign stuck in `publishing` | Queue worker/batch not running, or audience job failed | `queue:failed`, `notification_campaign_recipients`, campaign `/stats`; check `notifications-campaigns` worker. |
| Scheduled campaign never sends | Scheduler not running, time still in the future, or campaign cancelled | `php artisan schedule:run`; `php artisan notifications:campaigns`; campaign `scheduled_at`. |
| Notification appears in outbound channels but not the bell | User opted out of in-app; row has `archived_at` | Preference payload; `notifications.archived_at`. |
| Report values look wrong | Tenant timezone or currency configuration, or missing cost data | Tenant timezone/currency; report period bounds; `cost_coverage` rather than assuming zero. |
| Full test suite dies with memory exhaustion | Default PHP CLI memory limit too low for the route file | `php -d memory_limit=1G artisan test`. |
| FCM SDK fails to initialise | `ext-sodium` missing or credentials malformed | Install `sodium`; validate the service-account JSON; check worker logs. |

---

## 17. Known limitations

- FCM, Reverb, SMTP and WhatsApp are not live-verified in this environment.
- There is no Firebase Realtime Database and none should be introduced.
- The browser uses Reverb, not Firebase web push. Web push is optional and is
  not implemented as a separate browser transport; the settings endpoint
  reports push availability from the server's FCM configuration.
- The tenant/store/platform Reverb channels are authorised and available, but
  personal notification content is intentionally broadcast only on the
  recipient's private user channel to avoid cross-user leakage.
- There is no billing notification because billing does not exist in the
  product; do not invent subscription-plan targeting.
- Campaign audience selection is by all/tenant/user ids; there is no invented
  plan or segment concept.
- Flutter push cannot be claimed working until a real Firebase project,
  `google-services`/APNs configuration and a physical-device test exist.
- iOS requires manual Xcode capability and APNs configuration outside this
  repository.
