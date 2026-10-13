# Dukavo Backend

Multi-tenant SaaS API for Dukavo — a WhatsApp-first commerce workspace for
merchants (stores, catalogue, inventory, customers, orders, inbox) plus a
platform administration surface for Dukavo itself (tenants, and the marketing
site's content and lead inbox).

Built on Laravel 13, Sanctum and Fortify. A single versioned API lives under
`/api/v1`; the JSON contract is described below.

## Local setup

```bash
composer install
cp .env.example .env          # if needed
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # seeds roles, permissions and demo tenants
php artisan serve             # http://127.0.0.1:8000
```

Seeded logins use the password `password`:

| Email | Role | Scope |
| --- | --- | --- |
| `admin@gmail.com` | `super-admin` | platform |
| `support@dukavo.test` | `dukavo-admin` | platform |
| `claire@urban-basket.test` | `merchant` | tenant (`urban-basket`) |
| `marcus@urban-basket.test` | `staff` | tenant (`urban-basket`) |

## Running the tests

```bash
php artisan test
# or
vendor/bin/phpunit
```

The suite runs against an in-memory SQLite database (`:memory:`); no seeding of
`database.sqlite` is required to test.

## Conventions

### Response envelope

Single resources and actions returned through `App\Support\ApiResponse` share
one shape:

```json
{ "success": true, "message": "Role created.", "data": { "id": 5 } }
{ "success": false, "message": "...", "errors": { "email": ["..."] } }
```

Collection endpoints (`GET /tenant/roles`, `/tenant/products`, …) return
Laravel's resource wrapper instead — `{ "data": [...], "links": {...}, "meta": {...} }`
— so clients should read the collection from `data` and not rely on `success`
there. Public marketing reads (`/public/features`, `/public/pricing`,
`/public/faq`) do carry `success`, matching the rest of the action responses.

### Status codes

| Status | Meaning |
| --- | --- |
| `401` | Missing/invalid credentials |
| `403` | Authenticated but not allowed (permission/role boundary) |
| `404` | Resource not found |
| `409` | Request conflicts with a domain rule (`BusinessRuleViolation`) |
| `422` | Validation failure (`errors` key present) |

Domain-rule refusals (e.g. editing a system role, assigning a tenant role to a
non-member) return **409**, not 422.

### Access tiers

Every route belongs to one of three tiers, declared in `routes/api/v1.php`:

- **public** — no authentication (marketing site, provider webhooks).
* **platform** — any authenticated holder of a platform role, gated by the
  `platform` middleware.
* **tenant** — any member of the tenant resolved from the `X-Tenant` header,
  with a `permission:<slug>` requirement per route.

The active tenant is resolved by the `tenant` middleware from the caller's own
memberships, so a forged `X-Tenant` header cannot widen access. Send it on every
tenant-scoped request:

```
X-Tenant: urban-basket
```

### Authentication

The SPA authenticates with Sanctum's cookie session (obtain a CSRF cookie from
`GET /sanctum/csrf-cookie`, then send the `X-XSRF-TOKEN` header). Clients may
also authenticate with a bearer token issued by login. All authenticated
requests should send `Accept: application/json`.

```http
POST /api/v1/auth/login
{ "email": "claire@urban-basket.test", "password": "password" }

200 { "success": true, "data": { "token": "1|...", "user": { ... } } }
```

Registration is `POST /api/v1/auth/register` (Fortify). Logout is
`POST /api/v1/auth/logout`. The current user is `GET /api/v1/me`, which resolves
the active tenant when an `X-Tenant` header is supplied.

## Public (marketing) endpoints

No authentication. Write endpoints are covered by named per-IP throttle
limiters; all of them accept an optional `website` honeypot field which, when
filled, is silently discarded with the normal success payload.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/public/features` | Marketing feature list |
| `GET` | `/api/v1/public/pricing` | Published pricing plans |
| `GET` | `/api/v1/public/faq` | Published FAQ entries |
| `POST` | `/api/v1/public/waitlist` | Join the waitlist |
| `POST` | `/api/v1/public/contact` | Submit the contact form |
| `POST` | `/api/v1/public/newsletter/subscribe` | Subscribe to the newsletter |
| `POST` | `/api/v1/public/newsletter/unsubscribe` | Unsubscribe |

Waitlist (`POST /public/waitlist`):

```json
{ "email": "a@b.com", "name": "Ada", "business_type": "retail", "source": "landing" }
```

Contact (`POST /public/contact`):

```json
{ "name": "Ada", "email": "a@b.com", "subject": "Hello", "message": "..." }
```

Newsletter (`POST /public/newsletter/subscribe`):

```json
{ "email": "a@b.com", "source": "footer" }
```

## Onboarding

After registering, an authenticated (and active) account with no workspace
creates one. Only `name` is required; `slug` is derived from it and
`contact_email` defaults to the registered address. The caller is made the
owner server-side — a request can never place itself into a role.

```http
POST /api/v1/onboarding/tenant
{ "name": "Urban Basket", "store_name": "Main Store", "currency": "USD" }
```

## Platform administration

Prefix `/api/v1/platform`, gated by the `platform` middleware (any holder of a
platform role: `super-admin` or `dukavo-admin`). These read across tenants and
Dukavo's own marketing tables; they resolve no tenant context.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/platform/dashboard` | Platform overview |
| `GET` `POST` | `/platform/tenants` | List / create tenants |
| `GET` `PATCH` `DELETE` | `/platform/tenants/{tenant}` | Show / update / delete |
| `POST` | `/platform/tenants/{tenant}/suspend` | Suspend tenant |
| `POST` | `/platform/tenants/{tenant}/activate` | Reactivate tenant |
| `GET` | `/platform/tenants/{tenant}/members` | Tenant members |
| `GET` `POST` | `/platform/pricing-plans` | List / create plans |
| `GET` `PATCH` `DELETE` | `/platform/pricing-plans/{id}` | Show / update / delete |
| `GET` `POST` | `/platform/faq-entries` | List / create FAQ entries |
| `GET` `PATCH` `DELETE` | `/platform/faq-entries/{id}` | Show / update / delete |
| `GET` | `/platform/waitlist-entries` | Waitlist inbox |
| `PATCH` `DELETE` | `/platform/waitlist-entries/{id}` | Update / delete |
| `GET` | `/platform/contact-messages` | Contact inbox |
| `PATCH` `DELETE` | `/platform/contact-messages/{id}` | Update / delete |
| `GET` | `/platform/newsletter-subscribers` | Subscribers |
| `PATCH` `DELETE` | `/platform/newsletter-subscribers/{id}` | Update / delete |

## Tenant endpoints

Prefix `/api/v1/tenant`, gated by the `tenant` middleware (resolves `X-Tenant`
from the caller's memberships). Each route additionally requires the
`permission:<slug>` shown below.

### Roles & permissions

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| `GET` | `/tenant/roles` | `roles.view` | Tenant roles only when acting in a tenant |
| `POST` | `/tenant/roles` | `roles.manage` | Create a custom role |
| `GET` | `/tenant/roles/{role}` | `roles.view` | |
| `PATCH` | `/tenant/roles/{role}` | `roles.manage` | |
| `DELETE` | `/tenant/roles/{role}` | `roles.manage` | |
| `PUT` | `/tenant/roles/{role}/permissions` | `roles.manage` | Replace the permission set wholesale |
| `POST` | `/tenant/roles/{role}/assign` | `users.manage` | Grant a role to a user |
| `POST` | `/tenant/roles/{role}/revoke` | `users.manage` | Remove a role from a user |

Create a custom role (`scope` is `tenant` or `platform`):

```json
{ "name": "Supervisor", "slug": "supervisor", "scope": "tenant",
  "permissions": ["dashboard.view", "products.view"] }
```

Assign / revoke:

```json
{ "user_id": 4 }
```

**Platform-role boundary.** Platform roles (`super-admin`, `dukavo-admin`) can
only be managed by a platform operator — a super-admin or a holder of
`dukavo-admin`. Any other caller attempting `show`, `update`, `destroy`,
`permissions`, `assign` or `revoke` against a platform role receives `403`:

```json
{ "success": false, "message": "Platform roles can only be managed by Dukavo platform administrators." }
```

System roles (`merchant`, `staff`) cannot have their permission set changed
(`409`). Assigning a tenant role to a user who is not a member of the tenant is
also a `409`.

### Users, stores, catalogue, inventory, customers, orders, inbox, settings

| Resource | Prefix | Permissions |
| --- | --- | --- |
| Users | `/tenant/users` | `users.view`, `users.manage` |
| Stores | `/tenant/stores` (+ `POST /{store}/default`) | `stores.view`, `stores.manage` |
| Categories | `/tenant/categories` (+ `GET /tree`) | `categories.view`, `categories.manage` |
| Products | `/tenant/products` | `products.view`, `products.create`, `products.update`, `products.delete` |
| Inventory | `/tenant/inventory` (+ `low-stock`, `adjust`, `receive`, `{id}/movements`) | `inventory.view`, `inventory.manage` |
| Customers | `/tenant/customers` | `customers.view`, `customers.create`, `customers.update`, `customers.delete` |
| Orders | `/tenant/orders` (+ `{id}/transition`, `{id}/transitions`) | `orders.view`, `orders.create`, `orders.update`, `orders.delete` |
| Conversations | `/tenant/conversations` (+ `summary`, `{id}/messages`, `{id}/assign`) | `conversations.view`, `conversations.reply`, `conversations.assign` |
| WhatsApp accounts | `/tenant/whatsapp-accounts` (+ `verify`, `disconnect`) | `whatsapp.view`, `whatsapp.manage` |
| Dashboard | `/tenant/dashboard` (+ `summary`, `revenue`, `top-products`, `dead-stock`) | `dashboard.view` |
| Audit log | `/tenant/audit-logs` (+ `actions`) | `audit.view` |

The authoritative permission slug for every endpoint is declared next to the
route in `routes/api/v1.php`; the full catalogue lives in
`App\Support\PermissionRegistry`.

## Roles model

RBAC is custom (no Spatie). Seeded roles:

- Platform: `super-admin`, `dukavo-admin`.
- Tenant: `merchant` (all tenant permissions), `staff` (a deliberately limited
  subset — no `roles.*`, no `users.manage`).

A super-admin bypasses permission checks via a `Gate::before` hook. Custom
tenant roles hold any subset of the tenant permission slugs.

## Webhooks

`GET|POST /api/v1/webhooks/whatsapp` — Meta's callback. Unauthenticated by
necessity; the handler resolves the tenant from the connected account rather
than trusting the payload.
