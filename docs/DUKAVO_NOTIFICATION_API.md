# Dukavo notification API

**Status:** HTTP reference for the notification platform, 9 November 2026
**Base path:** `/api/v1`
**Implementation:** `backend/routes/api/v1.php`

> This document describes the API as implemented and exercised in tests. FCM,
> Reverb, SMTP and WhatsApp are **not live-verified** in this environment. An
> endpoint returning success means Laravel accepted the request and wrote the
> authoritative MySQL rows; it is not proof that an external provider delivered
> anything. See [Delivery truth](#10-delivery-truth) and
> [DUKAVO_NOTIFICATION_PLATFORM.md](DUKAVO_NOTIFICATION_PLATFORM.md#15-verification-status).

---

## 1. Conventions

### 1.1 Authentication and tenancy

- Every authenticated route uses Sanctum. Send the bearer token:
  `Authorization: Bearer <token>`.
- The SPA may use Sanctum's first-party cookie mode with CSRF instead.
- `EnsureUserIsActive` runs after authentication. A deactivated account is
  rejected even with a valid token.
- Routes that resolve a workspace accept `X-Tenant: <slug-or-id>`. The header is
  honoured only when the authenticated user is a member; an unverified id is
  never trusted.
- The notification centre, preferences and device routes are personal. They do
  not require a tenant header and cannot read another account's rows.
- Platform routes additionally require `EnsurePlatformAccess` and an explicit
  permission (`notifications.view` or `notifications.manage`).

### 1.2 Response envelope

Success:

```json
{
  "success": true,
  "message": "Notification preferences saved.",
  "data": { }
}
```

Paginated success carries `data` plus `meta`:

```json
{
  "success": true,
  "message": "",
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 41,
    "from": 1,
    "to": 20
  }
}
```

Failure:

```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": { "title": ["The title field is required."] },
  "code": null
}
```

Business-rule refusals use HTTP `409` and put a stable machine code in `code`.
Ordinary validation failures use `422` and field-keyed `errors`. Unknown or
foreign resources return `404`, never `403`, so existence is not disclosed.

### 1.3 Dates, ids and pagination

- Timestamps are ISO 8601 with an offset, for example
  `2026-11-09T08:00:00+00:00`.
- Notification ids are UUIDs. Delivery, device and campaign ids are integers;
  campaigns also expose a `uuid`.
- `per_page` is accepted by list endpoints where documented; the cap is `100`.
- `page` is 1-based.

### 1.4 Rate limits

| Limiter | Applies to | Limit |
| --- | --- | --- |
| `api` | All API routes | Framework default |
| `notification-devices` | `POST /profile/devices` | 30/min per account |
| `unsubscribe` | `GET`/`POST /unsubscribe/{token}` | 10/min per IP |
| `notification-campaigns` | Campaign batch jobs, not HTTP | `DUKAVO_NOTIFICATION_BROADCAST_PER_MINUTE` (default 600/min) |

Exceeding a limiter returns `429`.

---

## 2. Notification centre

These routes are for the signed-in account. There is no tenant header and no
platform permission.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/notifications` | Paginated visible notifications, newest first. |
| `GET` | `/notifications/unread-count` | `{ "count": n }` for the bell. |
| `GET` | `/notifications/{id}` | One owned notification; used by mobile deep links. |
| `POST` | `/notifications/read-all` | Mark all visible unread rows read; `{ "updated": n }`. |
| `POST` | `/notifications/{id}/read` | Mark one read; returns the resource. |
| `POST` | `/notifications/{id}/unread` | Mark one unread; returns the resource. |
| `DELETE` | `/notifications/{id}` | Delete an owned notification; `204`. |

List filters:

| Query | Values | Default |
| --- | --- | --- |
| `status` | `all`, `unread`, `read` | `all` |
| `category` | `orders`, `inventory`, `messages`, `automation`, `approvals`, `reports`, `announcements`, `team`, `security`, `leads`, `tenants`, campaign category | all |
| `severity` | `info`, `success`, `warning`, `critical` | all |
| `scope` | `platform`, `tenant` | all |
| `per_page` | `1`–`100` | `20` |
| `page` | page number | `1` |

Hidden rows (`archived_at` set by an in-app opt-out) are excluded from every
read route. A foreign id returns `404`.

Resource:

```json
{
  "id": "8c1e5b1e-8f4a-4d6f-9a2c-2f0f6f4a0b11",
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

`params` is the resource name for the stored notification data. It is not
called `data` because the envelope already owns that key.

Read, unread, delete and read-all broadcast `notification.state` on the owner's
private user channel after commit. Creating a notification broadcasts
`notification.created`. Realtime is an accelerator: clients must still fetch
list state from Laravel.

---

## 3. Realtime (Reverb)

### 3.1 Authorisation

Broadcasting auth is registered on the API stack:

```text
POST /broadcasting/auth
Authorization: Bearer <token>
```

It is authenticated with `auth:sanctum` and `active`. It is not under
`/api/v1`.

Private channels:

| Channel | Authorised when |
| --- | --- |
| `App.Models.User.{userId}` | Authenticated user id equals `{userId}`. |
| `tenant.{tenantId}` | The user belongs to that tenant. |
| `store.{storeId}` | The store exists and the user belongs to its tenant. |
| `platform.admins` | The user is a platform operator. |

Personal notification content is broadcast only on
`App.Models.User.{userId}`. Tenant, store and platform channels are authorised
for non-personal events but never receive another user's notification body.

### 3.2 Event names

| Event | Name | Payload |
| --- | --- | --- |
| `NotificationCreated` | `notification.created` | Notification resource plus fresh unread count. |
| `NotificationStateChanged` | `notification.state` | Action, ids and fresh unread count. |

When `VITE_REVERB_APP_KEY` is empty the Vue client does not connect and falls
back to 60-second unread polling. Nothing in the UI should claim realtime is
active when it is not.

---

## 4. Notification preferences

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/profile/notifications/channels` | Channel availability with a reason when unavailable. |
| `GET` | `/profile/notifications` | Resolved preference set for every channel/event. |
| `PUT` | `/profile/notifications` | Update supplied switches. Unknown keys are dropped. |

`GET /profile/notifications/channels`:

```json
{
  "email": { "available": true, "reason": null },
  "push": { "available": false, "reason": "push_credentials_missing" },
  "in_app": { "available": true, "reason": null },
  "whatsapp": { "available": false, "reason": "whatsapp_disabled" }
}
```

Preference body is a nested channel/event object. Only supplied booleans are
changed:

```json
{
  "email": { "product_news": true, "order_updates": true },
  "push": { "product_news": false },
  "in_app": { "security_alerts": true }
}
```

Channels: `email`, `push`, `in_app`, `whatsapp`.

Events: `order_updates`, `low_stock`, `new_messages`,
`conversation_assignments`, `delivery_failures`, `approvals`,
`weekly_summary`, `product_news`, `team_activity`, `security_alerts`.

Rules enforced by the server, not the client:

- `security_alerts` is forced on for every channel even if the payload says
  otherwise.
- `product_news` is opt-in on every channel, including in-app, and defaults off.
- Unavailable channels are reported as unavailable; the client should disable
  the switch rather than pretend it will send.
- A marketing campaign is skipped for a recipient without the matching channel
  consent.

---

## 5. Push devices

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/profile/devices` | Active devices for the caller. Token is never returned. |
| `POST` | `/profile/devices` | Register/upsert an installation. `notification-devices` limiter. |
| `POST` | `/profile/devices/unregister` | Revoke by token at logout; idempotent `204`. |
| `PATCH` | `/profile/devices/{id}` | Update metadata for an owned device. |
| `DELETE` | `/profile/devices/{id}` | Revoke an owned device; `204`. |

Registration:

```json
{
  "token": "<fcm-registration-token>",
  "device_id": "<stable installation id>",
  "platform": "android",
  "device_name": "Pixel 8",
  "app_version": "1.0.0",
  "locale": "en"
}
```

`platform` is one of `android`, `ios`, `web`, `unknown`. `locale` is `en` or
`ar`. `device_id` is the app's persisted installation id, not the FCM token.

Device resource:

```json
{
  "id": 7,
  "device_id": "b2f1…",
  "platform": "android",
  "device_name": "Pixel 8",
  "app_version": "1.0.0",
  "locale": "en",
  "active": true,
  "last_seen_at": "2026-11-09T08:00:00+00:00",
  "created_at": "2026-11-08T10:00:00+00:00"
}
```

Behaviour:

- The same token always maps to one `token_hash` row; repeated registration is
  an update, not a second row.
- A rotated token for the same `device_id` revokes the previous active row.
- A token registered by another account is reassigned after the old row is
  revoked, so a second-hand device stops receiving the previous owner's alerts.
- Unregister with an unknown token still returns `204` so logout is idempotent.
- A foreign device id returns `404`.

---

## 6. Platform email templates

Every route requires `EnsurePlatformAccess` and `notifications.view` (read) or
`notifications.manage` (write). Merchant tokens cannot reach them.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| `GET` | `/platform/email-templates` | view | Catalogue with defaults, overrides and effective copy. |
| `GET` | `/platform/email-templates/{key}` | view | One template. |
| `PATCH` | `/platform/email-templates/{key}` | manage | Store plain-text subject/heading/intro and optional enabled flag. |
| `POST` | `/platform/email-templates/{key}/preview` | view | Render EN or AR HTML/text with sample data. |
| `POST` | `/platform/email-templates/{key}/test` | manage | Send a real test through the configured mail transport. |
| `POST` | `/platform/email-templates/{key}/restore` | manage | Delete the override and use the shipped copy. |

`{key}` contains dots, for example `tenant.order.created` or
`account.welcome`; that is a valid route segment.

Template catalogue entry:

```json
{
  "key": "tenant.order.created",
  "kind": "notification",
  "group": "orders",
  "optional": false,
  "enabled": true,
  "variables": ["name", "order_number", "total", "workspace"],
  "label": "New order {order_number}",
  "sample": { "name": "Sara", "order_number": "ORD-1042" },
  "defaults": {
    "subject": { "en": "...", "ar": "..." },
    "heading": { "en": "...", "ar": "..." },
    "intro": { "en": "...", "ar": "..." }
  },
  "overrides": {
    "subject": { "en": "..." },
    "heading": {},
    "intro": {}
  },
  "effective": {
    "subject": { "en": "...", "ar": "..." },
    "heading": { "en": "...", "ar": "..." },
    "intro": { "en": "...", "ar": "..." }
  },
  "updated_at": "2026-11-09T08:00:00+00:00",
  "updated_by": 1
}
```

`defaults` is the shipped copy. `overrides` is what an operator stored and may
be partial. `effective` is the copy that will actually be used: a non-empty
override wins per field per locale, otherwise the shipped copy is used.

Update body:

```json
{
  "subject": { "en": "New order {order_number}", "ar": "طلب جديد {order_number}" },
  "heading": { "en": "New order", "ar": "طلب جديد" },
  "intro": { "en": "Hi {name}, …", "ar": "مرحبًا {name}، …" },
  "enabled": true
}
```

Rules:

- Copy is plain text only. HTML is not accepted; the branded layout is owned by
  the application.
- Subjects and headings are limited to 180 characters; intros to 2000.
- Only placeholders listed in `variables` are allowed. An unknown `{token}` is
  a `422`, so a raw placeholder can never reach a recipient.
- An empty string clears that override and falls back to the shipped copy.
- `enabled` applies only to optional templates. Mandatory account, security and
  operational templates stay enabled even if `enabled: false` is submitted.
- Optional templates: `tenant.announcement.product`,
  `tenant.announcement.release`, `tenant.report.weekly`,
  `tenant.report.monthly`, `tenant.report.performance`, `platform.broadcast`.
  Everything else is mandatory.
- Update and restore are audited.

Preview body:

```json
{
  "locale": "en",
  "heading": { "en": "Unsaved heading" },
  "intro": { "en": "Unsaved intro" }
}
```

Preview uses sample data and renders the real branded layout. Draft heading and
intro may be supplied so an operator can see unsaved copy; subject preview uses
the stored override. Response:

```json
{
  "locale": "en",
  "subject": "New order ORD-1042",
  "html": "<!doctype html>…",
  "text": "New order ORD-1042\n…",
  "content": { "dir": "ltr", "heading": "…", "intro": "…", "unsubscribe_url": null }
}
```

The Vue admin renders `html` inside a sandboxed iframe and never accepts raw
HTML from the operator.

Test body:

```json
{ "locale": "ar", "email": "operator@example.test" }
```

`email` defaults to the caller's address. Response:

```json
{
  "email": "operator@example.test",
  "locale": "ar",
  "subject": "طلب جديد ORD-1042",
  "template": "tenant.order.created",
  "transport": "smtp",
  "delivery_confirmed": false
}
```

`delivery_confirmed` is always `false`: handing a message to a mail transport
is not a delivery receipt. Test sends use sample data and never invent revenue
or order totals.

---

## 7. Platform delivery monitoring

Every route requires `EnsurePlatformAccess` and `notifications.view`, except
retry which requires `notifications.manage`.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| `GET` | `/platform/notification-deliveries` | view | Paginated delivery ledger. |
| `GET` | `/platform/notification-deliveries/{id}` | view | One delivery. |
| `POST` | `/platform/notification-deliveries/{id}/retry` | manage | Retry an eligible failed delivery. |

Filters:

| Query | Values |
| --- | --- |
| `status` | `pending`, `queued`, `processing`, `sent`, `delivered`, `failed`, `skipped`, `cancelled` |
| `channel` | `mail`, `push`, `whatsapp` |
| `event_key` | exact event key, for example `tenant.order.created` |
| `notification_id` | notification UUID |
| `tenant_id` | workspace id |
| `search` | matched against recipient reference, event key and error |
| `from`, `to` | date bounds on `created_at` |
| `per_page` | `1`–`100`; default `25` |

Delivery resource:

```json
{
  "id": 41,
  "notification_id": "8c1e…",
  "event_key": "tenant.order.created",
  "channel": "mail",
  "provider": "smtp",
  "status": "failed",
  "status_label": "Failed",
  "attempts": 2,
  "recipient": {
    "id": 17,
    "name": "Sara",
    "ref": "s***@example.test"
  },
  "tenant": { "id": 12, "name": "Sara Store" },
  "error": "Connection timed out",
  "failure_type": "temporary",
  "provider_message_id": null,
  "queued_at": "2026-11-09T08:00:00+00:00",
  "sent_at": null,
  "delivered_at": null,
  "failed_at": "2026-11-09T08:01:00+00:00",
  "created_at": "2026-11-09T08:00:00+00:00",
  "retryable": true
}
```

Privacy: the private notification body and `provider_payload` are deliberately
absent. An operator sees the channel answer and a masked destination, not a
merchant's message.

Retry eligibility is computed by the server and exposed as `retryable`:

- status must be `failed`;
- failure must not be permanent;
- channel must be `mail` or `push` (WhatsApp has no retry transport);
- the template must still exist.

The row is reset to `queued`, one job is dispatched after commit, and the
response returns the updated resource. A second retry sees a row that is no
longer `failed` and is refused. Refusal codes:

| Code | Meaning |
| --- | --- |
| `delivery_not_retryable` | Not in `failed` state. |
| `delivery_permanent_failure` | Provider reported a permanent failure. |
| `delivery_channel_not_retryable` | WhatsApp or another unsupported channel. |
| `delivery_template_missing` | Template no longer exists in the catalogue. |

`sent` means a provider accepted the message. `delivered` is reserved for a
provider receipt that says so. `skipped` means the platform deliberately did not
send (no consent, no destination, channel unavailable) and is not a failure.

---

## 8. Platform campaigns

All routes require `EnsurePlatformAccess` plus `notifications.view` or
`notifications.manage`.

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
| `POST` | `/platform/notification-campaigns/{id}/publish` | manage | Publish now. |
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

- `channels` must contain 1–3 of `in_app`, `push`, `mail`.
- `category` is one of `announcements`, `orders`, `inventory`, `messages`,
  `approvals`, `security`, `reports`, `product`, `billing`.
- `audience.type` is `all`, `tenants` or `users`.
- `exclude_user_ids` is applied to any audience type.
- `scheduled_at` must be in the future.
- `idempotency_key` prevents a double-submitted compose form from creating two
  campaigns.
- `is_marketing: true` is delivery-consent-gated per channel; it is not a
  licence to mail everyone.

Campaign resource counters are exposed as `counts.total`, `queued`, `sent`,
`failed` and `skipped`, with lifecycle flags `can_edit`, `can_publish` and
`can_cancel` derived from the same state machine the service enforces.

Lifecycle refusals are `409` with a machine code such as
`campaign_not_editable`, `campaign_cancelled`, `campaign_schedule_past`,
`campaign_not_publishable`. Ordinary validation failures are `422`.

Audience notes:

- `all` means active users with a workspace, excluding platform-only operators.
- Platform staff are excluded from every campaign audience.
- `skipped` in the ledger is intentional: no consent, no active device, or an
  unavailable channel.

---

## 9. Public unsubscribe

These two routes are the only anonymous notification mutations. No session,
Sanctum token or tenant header is required. The token is the authority.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/unsubscribe/{token}` | Report what the link would do; changes nothing. |
| `POST` | `/unsubscribe/{token}` | Apply the opt-out; RFC 8058 one-click target. |

Tokens are hashed at rest, scoped to one user, channel and category
(`product_news`), and expire after `DUKAVO_EMAIL_UNSUBSCRIBE_TTL_DAYS`
(default 90 days). The raw token is returned exactly once when issued.

`GET` success:

```json
{
  "valid": true,
  "already_used": false,
  "expired": false,
  "channel": "email",
  "category": "product_news",
  "email": "s***@example.test",
  "expires_at": "2027-02-07T08:00:00+00:00"
}
```

`POST` success:

```json
{
  "channel": "email",
  "category": "product_news"
}
```

Behaviour:

- Unknown, malformed or expired token: `410` with
  `"This unsubscribe link is not valid."` or
  `"This unsubscribe link has expired."`.
- A replayed `POST` on an already-used token is idempotent and returns success;
  the opt-out is not an error.
- `GET` masks the address so a leaked link is not an email enumeration tool.
- Only the optional category the token was issued for can be switched off.
  Security, account and operational mail are unaffected and cannot be disabled
  here.
- The action records an immutable `notification_consents` row with source
  `unsubscribe_link`, IP address and user agent.
- Rate limited to 10/min per IP.

The frontend accepts both `/unsubscribe?token=...` (the link placed in email)
and `/unsubscribe/{token}`.

Marketing email also advertises:

```text
List-Unsubscribe: <https://api.example.test/api/v1/unsubscribe/{token}>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

---

## 10. Delivery truth

The API separates four facts that are easy to conflate:

| Fact | Where it lives | Meaning |
| --- | --- | --- |
| Notification created | `notifications` | MySQL accepted the authoritative in-app record. |
| Delivery queued | `notification_deliveries.status = queued` | A job exists. It may not have run. |
| Provider accepted | `status = sent` | The mail/FCM/WhatsApp transport accepted the message. |
| Provider delivered | `status = delivered` | A receipt says the provider delivered it. |

A test email response always carries `delivery_confirmed: false`. A campaign
with `sent_count > 0` means transports accepted the messages, not that every
recipient read them. Do not describe FCM, Reverb, SMTP or WhatsApp as
operational on the strength of a green test suite; they are only operational
when this installation has been configured and a live send was observed.

Current environment:

| Transport | State |
| --- | --- |
| FCM | Not configured, no device; fake-transport tests only. |
| Reverb | `BROADCAST_CONNECTION=log`; no server or key. |
| SMTP | Array mailer in tests; no live SMTP. |
| WhatsApp | Disabled; no approved template or transport. |
| iOS push | No APNs key or capability. |

---

## 11. Error and edge-case summary

| Status | When |
| --- | --- |
| `401` | Missing/expired token, inactive account. |
| `403` | Platform route without platform access or permission. |
| `404` | Foreign/unknown notification, device, campaign, delivery or template. |
| `409` | Campaign lifecycle or delivery-retry refusal; body has `code`. |
| `410` | Unknown/expired unsubscribe token. |
| `422` | Field validation, unknown template placeholder, oversized copy. |
| `429` | Rate limit exceeded. |
| `500` | Unexpected server error; do not retry blindly. |

Idempotency:

- Notification dispatch claims one-shot events and cooldowns atomically.
- Campaign create accepts `idempotency_key`.
- Campaign recipient unique `(campaign_id, user_id)`.
- Notification dedupe `campaign:{uuid}:user:{id}`.
- Delivery unique `(notification_id, channel)`.
- A replayed unsubscribe `POST` is harmless.
- A retried delivery cannot enqueue two jobs because the row leaves `failed`
  before the response returns.
