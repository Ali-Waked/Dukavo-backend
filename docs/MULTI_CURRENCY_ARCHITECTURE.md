# Multi-currency architecture

Dukavo separates three currency concepts that are easy to conflate. Keeping them
apart is what lets a merchant change a store's pricing currency without
rewriting a shopper's past orders or another workspace's books.

| Currency | Owner | What it controls | Can it change prices? |
| --- | --- | --- | --- |
| **Profile currency** (`users.preferred_currency`) | The signed-in person | Display on personal surfaces such as the profile and platform screens | No |
| **Workspace currency** (`tenants.currency`) | The merchant workspace | The currency the workspace and its reports are labelled with | Only as a side effect of the default store's conversion |
| **Store currency** (`stores.currency`) | One store | The currency the store's current catalogue prices and costs are stored in | Yes, through an explicit conversion |
| **Storefront currency** | Public shop | What the shopper sees, derived from the store | No independent calculation |

The **store currency is the business authority**. The workspace currency mirrors
the default store's currency so navigation and workspace-level labels agree with
the prices they describe. The profile currency is a personal preference and is
never read by merchant pricing or reporting code.

## Sources of truth

- **ISO 4217 codes and minor units** come from the official SIX `list-one.xml`
  (published 2026-09-17).
- **English and Arabic names and symbols** come from ICU/CLDR.
- The generated result is `backend/config/currencies.php`; the SPA mirrors it in
  `frontend/src/utils/currencyData.ts` so formatting stays synchronous.
- No currency name is hand-written or model-generated. Running the generator
  again is the only supported way to add or correct an entry.

Currencies that cannot be used as a store pricing currency (metals, XDR, XSU and
the XXX "no currency" code) are excluded from the selectable catalogue.

Minor-unit precision is real, not a blanket two decimals:

| Currency | Decimals |
| --- | --- |
| JPY, KRW, many others | 0 |
| USD, EUR, EGP, most | 2 |
| BHD, IQD, JOD, KWD, LYD, OMR, TND | 3 |
| CLF, UYW | 4 |

Monetary columns are `decimal(18, 4)`, so a three- or four-decimal currency
survives one conversion without a second rounding. Rounding happens once, when
writing the converted business amount or when aggregating a report, using
`CurrencyCatalog::round()` or the target currency's `decimals`.

## Selecting a currency

Every selector is server-backed:

- `GET /api/v1/currencies` — authenticated, not tenant-scoped. Used by personal
  surfaces such as the profile.
- `GET /api/v1/tenant/currencies` — requires `stores.view`. Used by merchant
  screens.
- `GET /api/v1/tenant/stores/{store}/currency` — current currency plus history.

The payload contains `code`, localised `name`, `name_en`, `name_ar`, localised
`symbol`, `symbol_en`, `symbol_ar`, and `decimals`. Localisation follows
`?locale=`, then `Accept-Language`, defaulting to English.

The web and Flutter selectors search name, code and symbol in both languages,
are keyboard and screen-reader accessible, handle RTL through logical spacing,
and use a virtualised list for the full 165-currency catalogue. An empty search
shows an explicit empty state rather than a blank menu.

## Exchange rates

Rates are fetched on the server by `ExchangeRateService` through the configured
`ExchangeRateProvider` (OpenExchangeRates in production). Clients never fetch or
calculate a rate.

- Responses are cached; a stale cached quote is reported as stale rather than
  treated as fresh.
- If no reliable rate is available the request fails with
  `ExchangeRateUnavailable`. There is **no fallback rate, no hard-coded rate and
  no partial conversion**.
- Development fixtures are test-only and idempotent. They must never be loaded
  into a production database.

## Changing a store's currency

`POST /api/v1/tenant/stores/{store}/currency` requires `stores.manage`.

1. **Authorise and scope.** The route binds the store through tenant scoping, so
   one workspace cannot convert another's store.
2. **Validate.** The target must be a supported ISO code and carry an
   idempotency key.
3. **Fetch a rate.** A provider outage aborts before any row changes.
4. **Preview.** `GET .../currency/preview?currency=EUR` returns the rate, source,
   fetched-at timestamp, a sample of before/after prices, and counts for
   products, variants, fixed bundle discounts and shipping zones. The merchant
   must explicitly confirm.
5. **Transact.** The conversion runs inside a database transaction, locks the
   store, and chunks catalogue rows by id. It converts:
   - product price and cost,
   - variant price and cost,
   - fixed-amount bundle discounts,
   - shipping-zone fees and free-shipping thresholds,
   - the store's `currency` and `currency_decimals`,
   - the tenant currency when the converted store is the default store.
6. **Record the receipt.** A `currency_conversions` row stores the from/to
   currencies, rate, source, fetched timestamp, counts, requester and completion
   time.
7. **Idempotency.** The unique `idempotency_key` (scoped to the store) means a
   retried request returns the original receipt instead of converting twice.
   Uniqueness is the race guard, not a best-effort check.

Percentage bundle discounts are currency-independent and are deliberately left
alone.

### What never changes

Historical financial records keep their original currency and amount:

- orders and order items,
- payments,
- refunds,
- inventory ledger entries and stock movements,
- completed conversion receipts.

Those rows describe what actually happened. Relabelling or rescaling them would
falsify the books.

## Historical reporting

Reports convert historical order amounts using the **stored rate** from the
conversion that applied to that store, not today's quote. When an order's
currency cannot be converted with a stored rate, the report marks it as
unconvertible instead of silently excluding it or inventing a value.

The same `CurrencyCatalog` supplies formatting precision to:

- dashboard aggregates,
- analytics and recovery pipelines,
- profit intelligence,
- inventory valuation,
- customer lifetime spend,
- order and product screens,
- exported reports.

Every aggregate rounds to the reporting currency's ISO minor unit, so a KWD
report never shows a fraction of a fils and a JPY report never shows cents.

## Refreshing after a conversion

After a successful conversion the backend has already committed the new prices
and currencies. Clients then:

- invalidate the store, store-options, workspace-settings and dashboard/report
  queries,
- re-read `/me` so `active_tenant.currency` reflects the default store,
- re-render selectors from the refreshed currency payload.

The profile currency change is narrower: it updates only the user's preference
and never invalidates merchant prices, stores or reports.

## Seeding and defaults

- The default currency remains `USD` unless existing configuration says
  otherwise (`DUKAVO_DEFAULT_CURRENCY`).
- New workspaces create a default store with `USD`, two decimals and an audited
  currency state.
- Development exchange-rate fixtures are clearly test-only and can be run
  repeatedly without changing a second time.

## Tests

The guarantees above are pinned by:

- `CurrencyCatalogTest` — ISO membership, localised names/symbols, precision,
  search payload shape.
- `StoreCurrencyTest` — preview, confirmation, conversion of products,
  variants, bundles and shipping, idempotent replay, provider failure leaving
  rows untouched, history, tenant isolation, workspace-currency sync and store
  promotion.
- `ProfileSettingsTest` — profile currency persistence, validation, audit and
  independence from workspace/store currency.
- `TenantSettingsEndpointTest` — workspace currency cannot be changed directly
  and requires store conversion.
- Reporting, inventory, analytics and storefront suites — per-currency rounding,
  historical preservation and catalogue exposure.
- Flutter tests — currency parsing/search, picker search and empty state,
  profile/auth currency metadata, precision formatting and the store-currency
  confirmation flow.
