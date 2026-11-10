# Dukavo Payment Hub

Provider-independent online payments for Dukavo storefronts, alongside the
existing cash-on-delivery flow and a merchant-configured bank transfer.

> **Implementation status: `IMPLEMENTED, AWAITING CREDENTIALS`**
>
> The backend, merchant screens, storefront checkout and mobile order screen are
> built and covered by tests. The only online adapter that exists is Tap
> Payments, and its automated tests use a faked Tap transport, not a real
> account. Every store stays on cash on delivery / bank transfer until its
> merchant completes Tap onboarding and saves a provider-verified secret key.
> No live charge was made while building this feature. See
> [section K](#k-final-report) for the full status rationale.

---

## Contents

1. [How the pieces fit](#1-how-the-pieces-fit)
2. [Data model](#2-data-model)
3. [Country and provider catalogue](#3-country-and-provider-catalogue)
4. [Eligibility rules](#4-eligibility-rules)
5. [Merchant onboarding](#5-merchant-onboarding)
6. [Checkout and payment lifecycle](#6-checkout-and-payment-lifecycle)
7. [Webhooks](#7-webhooks)
8. [Refunds](#8-refunds)
9. [Platform administration](#9-platform-administration)
10. [Security and tenancy](#10-security-and-tenancy)
11. [Sandbox and live gates](#11-sandbox-and-live-gates)
12. [Activation runbook](#12-activation-runbook)
13. [Rollback and disabling payments](#13-rollback-and-disabling-payments)
14. [Testing](#14-testing)
15. [Known limitations](#15-known-limitations)
16. [Official sources](#16-official-sources)
17. [A–K report](#k-final-report)

---

## 1. How the pieces fit

The hub separates **provider-independent platform logic** from the **one adapter
that talks to a real gateway**. Adding a provider means writing an adapter and
seeding documented coverage — not editing checkout, refunds or the storefront.

```
                         ┌──────────────────────────────┐
Shopper (web / mobile) → │ Storefront checkout          │
                         │  server reprices the order   │
                         └──────────────┬───────────────┘
                                        │ PaymentMethod: cod | bank_transfer | online
                                        ▼
                         ┌──────────────────────────────┐
                         │ Storefront\PaymentService    │
                         │  PaymentEligibilityService   │
                         └──────────────┬───────────────┘
                                        │ online only
                                        ▼
        ┌───────────────────────────────────────────────────────┐
        │ Payment hub (provider-independent)                    │
        │                                                       │
        │ PaymentSessionService   PaymentStatusService          │
        │ PaymentWebhookService   PaymentRefundService          │
        │ PaymentConnectionService PaymentLedgerService         │
        │ PaymentCatalogService    Money                        │
        └───────────────────────────┬───────────────────────────┘
                                    │ PaymentProviderInterface
                                    ▼
                         ┌──────────────────────────────┐
                         │ TapPaymentProvider  (adapter)│
                         │  POST /v2/charges            │
                         │  POST /v2/refunds            │
                         │  GET  /v2/charges/{id}       │
                         └──────────────────────────────┘
```

Key files:

| Concern | Location |
| --- | --- |
| Contract and DTOs | `app/Services/Payments/Contracts/PaymentProviderInterface.php`, `app/Services/Payments/Data/` |
| Adapter resolution | `app/Services/Payments/PaymentProviderManager.php` |
| Tap adapter | `app/Services/Payments/Providers/TapPaymentProvider.php` |
| Eligibility | `app/Services/Payments/PaymentEligibilityService.php` |
| Checkout session/retry | `app/Services/Payments/PaymentSessionService.php` |
| Status reconciliation | `app/Services/Payments/PaymentStatusService.php` |
| Callback processing | `app/Services/Payments/PaymentWebhookService.php` |
| Refunds | `app/Services/Payments/PaymentRefundService.php` |
| Merchant connections | `app/Services/Payments/PaymentConnectionService.php` |
| Immutable ledger rows | `app/Services/Payments/PaymentLedgerService.php` |
| Decimal-safe money | `app/Support/Money.php` |
| Config | `config/payments.php` |
| Catalogue seed | `database/seeders/PaymentCatalogSeeder.php` |

The order-level `orders.payment_method` is one of `cod`, `bank_transfer` or
`online`. The specific rail (`card`, `mada`, `knet`, `benefit`, `qpay`,
`omannet`, `apple_pay`, `stc_pay`) is stored separately on
`payments.payment_method_code`, because a single online order can be retried
with a different rail without changing the order intent.

---

## 2. Data model

Migrations live in `database/migrations/2026_11_09_170*.php` and follow the
existing tenant-scoped conventions.

| Table | Purpose | Tenancy |
| --- | --- | --- |
| `payment_countries` | The 22 Arab markets, ISO code, name (EN/AR), currency, active flag, sort order | Global catalogue |
| `payment_providers` | Provider code, commercial status, `integration_enabled`, adapter class, documentation URLs | Global catalogue |
| `payment_provider_countries` | Documented coverage: settlement currency, `merchant_onboarding`, `transaction_capable`, source URL, verified date | Global catalogue |
| `payment_provider_capabilities` | `charges`, `status`, `refunds`, `webhooks`, `connection_test` | Global catalogue |
| `payment_methods` | Rail definitions: card, Mada, KNET, Benefit, QPay, OmanNet, Apple Pay, STC Pay | Global catalogue |
| `payment_provider_methods` | Provider source id per rail, optionally per country, with priority | Global catalogue |
| `store_payment_accounts` | Encrypted merchant credentials, environment, verification evidence | Tenant/store |
| `payments` | The attempt for one order: rail, amount, currency, provider reference, status, expiry | Tenant/store |
| `payment_transactions` | Append-only ledger of provider and internal events | Tenant/store |
| `payment_refunds` | Refund instruction, amount, provider reference, status | Tenant/store |
| `payment_webhook_events` | Raw callback evidence, signature result, processing result | **Deliberately global** |

`payment_webhook_events` is not tenant-scoped because an unknown or forged
callback may not map to a tenant at all. Once a callback is matched to a
payment, the handler enters that payment's `TenantContext` before touching
tenant-scoped models.

Monetary columns are `decimal(18,4)`
(`2026_11_01_065500_widen_monetary_precision.php`) and are written through
`App\Support\Money`, which uses `bcmath` and the currency's own minor-unit
precision. Floats are never used for arithmetic.

`PaymentProviderMethod` resolution favours a country-specific row over a global
row for the same rail, so a Kuwaiti KNET source id cannot leak into a Saudi
checkout.

---

## 3. Country and provider catalogue

All 22 Arab countries are seeded so the platform can report and manage them
without a deployment:

Saudi Arabia, United Arab Emirates, Kuwait, Bahrain, Qatar, Oman, Egypt, Jordan,
Lebanon, Iraq, Syria, Yemen, Palestine, Libya, Tunisia, Algeria, Morocco,
Mauritania, Somalia, Djibouti, Comoros, Sudan.

A row exists for every country, but **coverage does not**. Provider coverage is
written only where the provider's own documentation supports it.

### Tap Payments (adapter exists)

| Country | Settlement | New merchant onboarding | Transaction capable | Note |
| --- | --- | --- | --- | --- |
| SA | SAR | Yes | Yes | New merchants accepted |
| AE | AED | Yes | Yes | New merchants accepted |
| KW | KWD | Yes | Yes | New merchants accepted |
| BH | BHD | Yes | Yes | New merchants accepted |
| QA | QAR | Yes | Yes | New merchants accepted |
| OM | OMR | Yes | Yes | New merchants accepted |
| EG | EGP | **No** | Yes | Existing merchants only |
| JO | JOD | **No** | Yes | Existing merchants only |

Tap's support article also states that new merchants are not accepted from
Lebanon. No Tap coverage is recorded for the remaining 13 markets. This is a
deliberate absence, not an omission: a store in those markets keeps cash on
delivery and bank transfer.

Seeded Tap rails:

| Rail | Country scope | Source id |
| --- | --- | --- |
| `card` | All Tap markets | `src_card` |
| `apple_pay` | All Tap markets | `src_apple_pay` |
| `knet` | Kuwait | `src_kw.knet` |
| `benefit` | Bahrain | `src_bh.benefit` |
| `omannet` | Oman | `src_omannet` |

`mada`, `qpay` and `stc_pay` are defined as platform rail definitions but are
not attached to Tap because the adapter has no verified Tap source id for them
in this codebase. They remain invisible until an operator adds an active
provider method.

### MyFatoorah (coverage recorded, integration disabled)

MyFatoorah's live-account documentation states that merchants register per
country and activate through a local account manager. Coverage is recorded for
Kuwait, Saudi Arabia, Bahrain, the UAE, Qatar, Egypt, Oman and Jordan, but
`integration_enabled` is `false` and there is no adapter. Merchants cannot
connect it and checkout cannot choose it.

### Other candidate providers

Paymob, HyperPay, Stripe and PayPal are **not catalogued**. Their country
coverage was not verified against official documentation in this work, so no
row was invented for them. Adding one requires the same evidence standard,
documented in [section 9](#9-platform-administration).

---

## 4. Eligibility rules

Online payment is offered only when every one of these is true. The list is
cumulative and fails closed:

1. The store has a `country` set.
2. That country exists in `payment_countries` and `is_active`.
3. A provider in that country has `merchant_onboarding = true`,
   `transaction_capable = true`, and a settlement currency equal to the store
   currency.
4. The provider's `status` is `active` and `integration_enabled = true`, and its
   adapter is registered in `PaymentProviderManager::adapters()`.
5. The store has a `store_payment_accounts` row with `status = active`, a
   non-null `last_verified_at`, and an environment equal to
   `config('payments.checkout_environment')`.
6. The provider has an active source id for the requested rail in that country
   (or a global rail).

The eligibility service returns both options and human-readable reasons.
`Storefront\PaymentService::methods()` reports an unavailable online method with
the first reason rather than hiding the section entirely, so a merchant or
shopper can see *why* it is unavailable.

Checkout never accepts a provider, account, amount or currency from the browser.
A `payment_method_code` in the request is a rail name only, re-resolved against
the server catalogue.

### Repricing

`StorefrontCheckoutService` recomputes every line, discount, tax and shipping
amount from the database and the active shipping zone. The resulting
`grand_total`, currency and store are what get stored on the order and sent to
the provider. A tampered cart payload cannot change the charge.

---

## 5. Merchant onboarding

### 5.1 Prerequisites

Before a merchant can offer online payment:

1. The store must have its **country** set to a supported market and its
   currency must match that provider's settlement currency. Currency changes use
   the existing conversion flow; do not silently rewrite historical prices.
2. The merchant needs an approved provider account:
   - Tap merchant onboarding in SA, AE, KW, BH, QA or OM;
   - a Tap secret key — `sk_test_…` for sandbox, `sk_live_…` for production;
   - the webhook secret configured in the Tap dashboard
     (`hashstring` generation secret).
3. The merchant needs `payments.manage` to save a connection and
   `payments.refund` to refund.

### 5.2 Save a connection

`POST /api/v1/tenant/stores/{store}/payment-connections`

```json
{
  "provider_code": "tap",
  "environment": "sandbox",
  "credentials": { "secret_key": "sk_test_…" },
  "webhook_secret": "the-tap-webhook-secret",
  "merchant_reference": "optional internal reference"
}
```

The secret is written through the model's encrypted cast and is never returned
by any endpoint. The connection starts as `pending`; saving a key is not proof
that it works.

### 5.3 Verify

`POST /api/v1/tenant/stores/{store}/payment-connections/{account}/verify`

The adapter makes a bounded `GET /v2/charges?limit=1` call with the merchant
key. Success moves the connection to `active` and records
`last_verified_at`; failure leaves it `pending` and stores the provider's
message. Checkout ignores unverified connections.

Re-saving a connection re-runs verification. Revoking sets `revoked` and
immediately removes the store's online methods; past payments and their history
remain readable.

### 5.4 Tell the merchant what to do

The merchant UI is **Settings → Payments** in the SPA:

- cash on delivery on/off plus instructions;
- bank transfer on/off plus instructions;
- online connections: provider list with documented coverage, "Test connection",
  status and last verification;
- payment history with rail, amount, status, provider reference and refund
  actions.

The platform never asks a merchant to paste a key into chat, email or `.env`.

---

## 6. Checkout and payment lifecycle

### 6.1 Order creation

1. The shopper picks a payment method. Online rails are offered only by
   eligibility rules above.
2. `POST /api/v1/public/storefront/{tenant}/{store}/checkout` validates the
   cart, address, idempotency key and optional rail.
3. `StorefrontCheckoutService` reprices the order, reserves stock and writes the
   order inside a transaction.
4. For `online`, a payment attempt is created **after** the order commit. If the
   provider refuses, the order survives with a failed attempt and a retry path.
   The response never reports a successful payment at this stage.

### 6.2 Hosted redirect

`PaymentSessionService` builds a Tap charge:

- `amount` from the server-priced order, normalised to the currency's minor
  unit;
- `currency` from the order;
- `source.id` from the selected active rail;
- `reference.order` = order number, `reference.transaction` = attempt number;
- `redirect.url` and `post.url` = the SPA order page built from
  `PAYMENT_RETURN_PATH` and the order's unguessable tracking token.

The customer is sent to `transaction.url` (the provider-hosted page). The
browser redirect is treated as **navigation only**. Opening the return URL does
not mark anything paid.

### 6.3 Status

Order status is exposed publicly at
`GET /api/v1/public/storefront/{tenant}/{store}/orders/{token}` and
`GET .../orders/{token}/payment`. The projection contains only:

- attempt status and label, rail, attempt number, amount, currency;
- provider display name;
- the hosted checkout URL while the attempt is still payable;
- a masked provider reference, failure reason, expiry, paid timestamp;
- whether a retry is allowed.

Provider keys, client references, raw gateway payloads and internal ids are
absent. The SPA polls every 15 seconds while an attempt is pending; mobile does
the same. Polling reads local database state and does not multiply provider
traffic.

### 6.4 Retry

`POST .../orders/{token}/payment` with an optional `payment_method_code` starts
the next attempt. The token is the only authority. A new attempt is created when
the previous one failed, was cancelled, expired, or the local session TTL
elapsed. A succeeded payment cannot be retried.

### 6.5 Reconciliation

`POST /api/v1/tenant/payments/{payment}/sync` asks the provider for its current
answer. This is a merchant action; the public poll endpoint never calls the
provider. A lookup must match amount, currency and provider reference before it
can move an order to paid.

---

## 7. Webhooks

Endpoint:

```
POST https://<api-host>/api/v1/webhooks/payments/tap
```

No Sanctum token and no tenant header. The callback is matched to an attempt
from its own references, then verified with that merchant's webhook secret.

### 7.1 Signature

Tap sends a `hashstring` header: HMAC-SHA256 with the merchant's webhook secret
over the concatenation of the documented fields:

```
x_id{id}x_amount{amount}x_currency{currency}x_gateway_reference{gateway}x_payment_reference{payment}x_status{status}x_created{created}
```

The adapter tries the safe amount-format variants Tap is known to use (for
example `1.000` vs `1.00`) and accepts only a constant-time match. A missing or
invalid signature is stored as failed evidence and can never move an order to
paid.

### 7.2 Recording and idempotency

- The raw body is stored **before** any financial decision.
- Duplicate delivery is detected by a unique `(provider_id, event_id)` index.
- The event id is `${payload.id}_${first32(sha256(rawBody))}` because Tap can
  send several status callbacks for the same charge; deduplicating on
  `payload.id` alone would drop a later, final status.
- A successful callback must match the stored amount, currency and provider
  reference. A mismatch is recorded as failed, not applied.
- Processing enters the owning payment's `TenantContext` before touching
  tenant-scoped models.
- The handler is idempotent: a replayed valid callback acknowledges without
  repeating the ledger entry or the status transition.

### 7.3 Response codes

| Situation | Response |
| --- | --- |
| Unknown provider slug | 404 |
| Unparseable payload | 400 |
| Invalid signature | 400, stored as failed |
| Unknown attempt reference | 200, stored as ignored |
| Duplicate event | 200, no-op |
| Successfully applied | 200 |

The route is protected by the named `payment-webhook` rate limiter so an
anonymous flood cannot reach the HMAC path at unbounded rate.

### 7.4 Configuring Tap

In the Tap dashboard, set the webhook URL to the production API endpoint above,
generate the webhook secret, and enter that same secret when saving the merchant
connection. The secret is per connection, encrypted at rest. Rotating it means
saving the connection again before the next callback.

---

## 8. Refunds

Refunds are merchant-authorized and bounded on the server:

1. The payment must belong to the merchant's tenant and store (route model
   binding + global scope make a foreign id a 404).
2. The payment must have a successful captured charge.
3. The provider must advertise the `refunds` capability.
4. The requested amount must be `> 0` and no more than
   `captured amount − successful/pending refunds already recorded`.
5. The currency is the payment currency; a refund cannot change it.

`POST /api/v1/tenant/payments/{payment}/refunds`

```json
{ "amount": "25.00", "reason": "Damaged item", "idempotency_key": "optional-stable-key" }
```

The provider call uses `POST /v2/refunds` with the original charge id. A
provider refusal creates no successful refund row. A partial refund leaves the
remaining refundable amount intact. Refund rows and their provider references
are immutable once written; corrections are new rows.

The merchant needs the `payments.refund` permission, which is separate from
`payments.manage`.

---

## 9. Platform administration

Platform operators manage availability, never secrets. Every platform payment
endpoint is under `/api/v1/platform/payments` and requires
`platform_payments.view` or `platform_payments.manage`.

| Action | Endpoint |
| --- | --- |
| List countries and coverage | `GET /countries` |
| Enable/pause a country, change sort order | `PATCH /countries/{country:code}` |
| List providers, coverage, capabilities, methods | `GET /providers` |
| Add/update a provider | `POST /providers`, `PATCH /providers/{provider:code}` |
| Add/update/remove documented coverage | `POST/PATCH/DELETE /providers/{provider:code}/countries…` |
| Add/update/remove a provider rail | `POST/PATCH/DELETE /providers/{provider:code}/methods…` |
| Manage rail definitions | `GET/POST/PATCH/DELETE /methods…` |
| Inspect callback evidence | `GET /webhook-events`, `GET /webhook-events/{event}` |

Provider and country routes bind by **code**, not numeric id, so a copied URL
keeps its meaning across environments.

### Adding a provider honestly

1. Verify coverage against the provider's own documentation.
2. Seed the provider with `status = pending` and `integration_enabled = false`.
3. Add coverage rows with `source_url` and `verified_at`.
4. Write and register an adapter in `PaymentProviderManager::adapters()`.
5. Add and test the provider's rails with real source ids.
6. Only then set `integration_enabled = true` and `status = active`.

The platform UI shows both flags and a warning when a provider is documented
but not integrated. There is no "fake it" switch: a provider without an adapter
cannot be resolved and cannot appear at checkout.

### Webhook evidence

Operators can read the stored payload, signature validity, processing status and
failure reason for any callback. The stored payload is scrubbed of secrets.
Operators cannot read merchant connection credentials through any platform
route.

---

## 10. Security and tenancy

| Requirement | Implementation |
| --- | --- |
| Credentials encrypted | `StorePaymentAccount` encrypted cast; `$hidden`; never serialised |
| No secret in logs or audit snapshots | Adapter reads the key per call; snapshots exclude it |
| Tenant isolation | Global scopes on payments, refunds, transactions, accounts; `scopeBindings()` on nested routes |
| Platform cannot read secrets | No platform route returns a connection credential |
| Write-only credentials | Save/verify/revoke only; list returns status and evidence |
| Server-side pricing | Order totals recomputed from database rows |
| Browser cannot confirm payment | Only signed webhook or explicit provider lookup can mark paid |
| Amount/currency/reference checks | Required before a callback may succeed |
| Idempotency | Unique provider event id; checkout idempotency key; optional refund key |
| Immutable financial rows | Transactions append-only; refunds never edited |
| Decimal-safe money | `App\Support\Money` on `bcmath`, per-currency minor units |
| Environment separation | Checkout uses only connections matching `payments.checkout_environment` |
| Fail closed | Every missing configuration returns unavailable, never a fake success |
| Rate limiting | Named limiter on callbacks and on public retry |
| No invented coverage | Every coverage row carries a source URL and verified date |

Provider callback and checkout failures never expose the secret, the raw
provider body or a stack trace to the shopper.

---

## 11. Sandbox and live gates

`config('payments.checkout_environment')` is the gate:

- `sandbox` outside production (the default);
- `live` on the production host only, after approval;
- checkout only considers connections whose `environment` matches.

A development copy therefore cannot charge a live card even if a live key was
pasted into the UI by mistake. Conversely, a production host with sandbox
connections will offer no online methods rather than use a test key silently.

Additional gates:

- `PAYMENT_DEFAULT_PROVIDER` is documentation/default resolution, not a
  permission to charge.
- `payment_providers.integration_enabled` must be true and an adapter must be
  registered.
- `payment_countries.is_active` can pause a whole market instantly.
- A provider's `status` can be set to `inactive`/`disabled` to stop new
  sessions while existing charges can still be verified.

---

## 12. Activation runbook

Perform this on a staging host first. Never run a live charge as part of setup.

### 12.1 Deploy

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=PaymentCatalogSeeder --force
php artisan config:cache
php artisan route:cache
```

`PaymentCatalogSeeder` is idempotent: it updates countries, rails and provider
coverage by their stable keys and does not duplicate rows. Do not run the demo
seeders in production.

### 12.2 Configure the host

```dotenv
APP_ENV=production
APP_URL=https://api.example.com
PAYMENT_DEFAULT_PROVIDER=tap
PAYMENT_CHECKOUT_ENVIRONMENT=live
PAYMENT_RETURN_PATH=/shop/{tenant}/{store}/orders/{token}
PAYMENT_SESSION_TTL_MINUTES=60
TAP_API_BASE_URL=https://api.tap.company/v2
TAP_TIMEOUT_SECONDS=20
TAP_CONNECT_TIMEOUT_SECONDS=10
```

No payment secret belongs in `.env`. Run `php artisan config:cache` after
changing any of the above.

### 12.3 Provider setup

1. Complete Tap merchant onboarding for the store's country.
2. Obtain the live secret key.
3. Set the Tap webhook URL to
   `https://<api-host>/api/v1/webhooks/payments/tap`.
4. Generate/record the Tap webhook secret.
5. In Dukavo, open **Settings → Payments**, save the live connection, then press
   **Test connection**. Confirm status `active`.

### 12.4 Verify one transaction

1. Put the store country/currency on a supported pair.
2. Confirm online rails appear at checkout and COD/bank transfer behave as
   configured.
3. Make one small real purchase with a real card (or the provider's approved
   live test procedure).
4. Confirm the order becomes paid only after the signed callback or a manual
   sync.
5. Confirm the callback appears in platform webhook evidence with a valid
   signature.
6. Refund the charge in full and confirm both the provider and Dukavo record it.
7. Confirm the order page shows the paid state and no stale checkout URL.

### 12.5 Operate

- Watch `payment_webhook_events` for `signature_valid = false` or repeated
  failures.
- Watch failed payment attempts and provider error messages.
- Keep the queue worker running; payment notifications use the existing
  notification dispatcher.
- Rotate a webhook secret by saving the connection again immediately after the
  provider change.
- Never edit financial rows in the database; correct with a new refund or a new
  attempt.

---

## 13. Rollback and disabling payments

Fastest to safest:

1. **One store:** revoke its connection. Online methods disappear on the next
   catalogue read; historical payments stay readable.
2. **One market:** set `payment_countries.is_active = false` in the platform
   catalogue. Checkout refuses online payment there.
3. **One provider:** set `payment_providers.integration_enabled = false` and/or
   `status = inactive`. New sessions stop; existing callbacks can still be
   verified.
4. **Whole hub:** set `PAYMENT_CHECKOUT_ENVIRONMENT` to a value with no matching
   connections (for example `disabled`), then `php artisan config:cache`.
   Checkout reports online unavailable; COD and bank transfer continue.
5. **Code rollback:** revert the payment commit. The migrations are additive, so
   a rollback does not destroy order history; `php artisan migrate:rollback`
   removes the payment tables only if you explicitly roll those batches back.

Disabling online payment must never mark an unpaid order as paid. Existing
`payments` rows remain as the audit trail.

---

## 14. Testing

### Backend

```bash
php -d memory_limit=512M vendor/bin/phpunit
```

The full suite needs more than PHP's default 128 MB because of route/model
bootstrapping; 512 MB is sufficient.

Payment coverage:

- `tests/Feature/PaymentCatalogEligibilityTest.php` — 22 countries, Tap
  onboarding vs transaction capability, MyFatoorah disabled, source ids per
  country, missing store country, inactive market, unverified connection,
  verified connection, settlement-currency mismatch, country-specific rail
  precedence.
- `tests/Feature/PaymentFlowTest.php` — hosted checkout against a faked Tap
  transport, server repricing, failed attempt + retry, signed callback, invalid
  signature, duplicate delivery, amount/currency/reference mismatch, tenant
  isolation, refund ceiling, refund capability, masked projection.
- `tests/Feature/StorefrontSettingsEndpointTest.php` — method ordering and
  merchant settings for COD, bank transfer and unavailable online.

### Frontend

```bash
npx vue-tsc --build --force
npx vite build
npm test
```

### Mobile

```bash
flutter analyze lib/features/shop lib/core/api lib/features/payments
flutter test test/features/shop test/features/payments
```

All payment tests use fakes and fixtures. No test contacts a real provider and
no credential is committed.

---

## 15. Known limitations

1. **Tap is the only adapter.** MyFatoorah coverage is recorded but has no
   adapter and is disabled. Paymob, HyperPay, Stripe and PayPal are not
   catalogued because their coverage was not verified here.
2. **Tap does not onboard new merchants in Egypt, Jordan or Lebanon.** Stores
   there can only use cash on delivery and bank transfer unless they already
   hold a Tap account (Egypt/Jordan) or another provider is approved.
3. **Mada, QPay and STC Pay have no Tap source id in this codebase.** They are
   defined as rails but not offered. Verify the source id against Tap before
   activating them.
4. **Mobile cannot open the hosted checkout in an in-app browser.** The app
   shows the secure URL and a copy action because `url_launcher` /
   `webview_flutter` are not dependencies. Adding one requires a product
   decision and a dependency review.
5. **No partial-capture or multi-capture flow.** Refunds are bounded by the
   captured amount; a capture model can be added when a provider requires it.
6. **No stored-payment-method / tokenisation flow.** Every checkout is a fresh
   hosted charge.
7. **No automatic provider reconciliation job.** Status is refreshed by
   callbacks or an explicit merchant sync. A scheduled reconciliation job is a
   sensible next step for missed callbacks.
8. **Currency conversion is not performed at checkout.** The store currency must
   equal the provider's settlement currency; mixing them is blocked rather than
   silently converted.

---

## 16. Official sources

Verified while implementing this feature:

| Claim | Source |
| --- | --- |
| Tap merchant-country availability, including onboarding status | <https://support.tap.company/en/support/solutions/articles/153000140298-what-countries-are-supported-by-tap-payments-> |
| Tap create-charge API, `source.id`, hosted `transaction.url`, references | <https://developers.tap.company/reference/create-a-charge> |
| Tap webhook payload and `hashstring` verification | <https://developers.tap.company/docs/webhook> |
| MyFatoorah live-account country onboarding model | <https://docs.myfatoorah.com/docs/live-account> |

Coverage rows store their own `source_url` and `verified_at`, so a future
reviewer can see when a claim was last checked.

---

## K. Final report

### A. Scope

A provider-independent payment hub for all 22 Arab markets: platform catalogue,
merchant onboarding, hosted online checkout, signed webhooks, reconciliation,
refunds, platform administration, security, tests and activation documentation.
Cash on delivery was preserved and bank transfer was added as a merchant
setting.

### B. Architecture

`PaymentProviderInterface` + `PaymentProviderManager` + DTOs isolate the gateway.
Services own eligibility, sessions, status, webhooks, refunds, connections and
ledger writes. The database catalogue decides availability; code only decides
which adapters exist.

### C. Countries

All 22 Arab countries are seeded and manageable at runtime. A country row means
"the platform can operate here", not "online payment works here". Online payment
requires documented provider coverage, a settlement-currency match and a
verified connection.

### D. Providers

Tap Payments is implemented for Saudi Arabia, the UAE, Kuwait, Bahrain, Qatar
and Oman, and recorded as transaction-capable but closed to new merchant
onboarding in Egypt and Jordan. MyFatoorah coverage is recorded from official
documentation but its integration is disabled. No other provider has invented
coverage.

### E. Merchant onboarding

Merchants save an encrypted, provider-verified connection per store and per
environment. Secrets are write-only. A connection is only `active` after the
provider accepts the key, and only active connections produce checkout options.

### F. Checkout

Checkout is server-priced, provider-hosted and fail-closed. The order is
committed before a charge is attempted; a provider refusal produces a retryable
failed attempt, never a fake success. The browser redirect is navigation only.

### G. Webhooks and reconciliation

Callbacks are recorded before processing, verified per merchant with Tap's
`hashstring` HMAC, deduplicated by event id plus raw-body hash, validated against
the stored amount/currency/reference, and applied inside the owning tenant.
Merchants can force a provider status lookup; the public order page only polls
local state.

### H. Refunds

Refunds are tenant-scoped, permissioned separately, bounded by the captured
amount minus earlier refunds, and require the provider's refund capability.
Partial refunds are supported; refund rows are immutable.

### I. Security

Encrypted credentials, no platform access to merchant secrets, tenant scoping,
server repricing, signed callbacks, idempotency, decimal-safe money,
environment gating, rate limiting and append-only financial evidence are all in
place. No credential was committed and no live transaction was run.

### J. Testing

Backend: the payment catalogue/eligibility suite and the end-to-end flow suite
cover countries, coverage, eligibility, repricing, failure/retry, signatures,
duplicates, mismatches, isolation and refund limits. Frontend: type-check, build
and the JavaScript test suite pass. Mobile: analyzer clean for the touched
features and the shop/payments tests pass. Provider traffic is faked.

### K. Status

**`IMPLEMENTED, AWAITING CREDENTIALS`**

The feature is implemented end to end and sandbox-tested against a faked Tap
transport. It is not live: a real Tap merchant account and its sandbox/live
credentials are still required, and Tap merchant approval is a prerequisite for
live keys in every market. Until a merchant saves a verified connection:
online payment is correctly unavailable, and COD/bank transfer continue to work.

Next actions, in order:

1. Complete Tap merchant onboarding in the first target market.
2. Save and verify a Tap sandbox connection on staging.
3. Run one sandbox charge, one signed callback and one refund.
4. Repeat on live with a small real charge, then enable online payment per store.
5. Add a second adapter only after its coverage is documented from official
   sources.
