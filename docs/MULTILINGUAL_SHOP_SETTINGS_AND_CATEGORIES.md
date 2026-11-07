# Multilingual shop settings and categories

Dukavo stores merchant content in two languages without duplicating the records
that own prices, stock, slugs or tenant ownership. English remains the canonical
column and Arabic lives beside it, following the same `*_ar` pattern already used
by newsletters and FAQ entries.

## What is translated

| Surface | Canonical English | Arabic | Notes |
| --- | --- | --- | --- |
| Category name | `categories.name` | `categories.name_ar` | Slug, hierarchy and product links are shared. |
| Category description | `categories.description` | `categories.description_ar` | Optional in both languages. |
| Store name | `stores.name` | `stores.name_ar` | Same store, inventory and currency. |
| Store description | `stores.description` | `stores.description_ar` | Optional in both languages. |
| Storefront tagline | `settings.tagline` | `settings.tagline_ar` | Stored in `storefront_settings` JSON. |
| Storefront about | `settings.about` | `settings.about_ar` | Stored in `storefront_settings` JSON. |
| Storefront address | `settings.address` | `settings.address_ar` | Stored in `storefront_settings` JSON. |
| Opening hours | `settings.opening_hours` | `settings.opening_hours_ar` | Stored in `storefront_settings` JSON. |
| Delivery note | `settings.delivery_note` | `settings.delivery_note_ar` | Stored in `storefront_settings` JSON. |
| Return policy | `settings.return_policy` | `settings.return_policy_ar` | Stored in `storefront_settings` JSON. |
| Search title | `settings.meta_title` | `settings.meta_title_ar` | Stored in `storefront_settings` JSON. |
| Search description | `settings.meta_description` | `settings.meta_description_ar` | Stored in `storefront_settings` JSON. |

Never translated: currency codes and metadata, timezone, email addresses, phone
numbers, URLs, social handles, numeric settings, IDs, slugs, permissions and
credentials. Contact details and display switches are shared by both languages.

## Locale resolution

`App\Support\PreferredLocale::from($request)` resolves the language for a read:

1. An explicit `?locale=ar` or `?locale=en`.
2. A supported language in `Accept-Language`.
3. The configured default, English.

Unsupported languages degrade to the default instead of returning a 404 or an
empty payload. Public endpoints also return the resolved language as `locale` so
a client can set text direction and know which copy it received.

## Fallback rules

`App\Support\LocalizedText::pick()` treats a blank or missing translation as
missing, not as an intentional empty value:

- Arabic request: use `*_ar` when it has visible text, otherwise the English
  value.
- English request: use the English value when it has visible text, otherwise
  Arabic.
- Fallback is per field. An Arabic tagline with no Arabic about text still shows
  the English about text.

The same rule is implemented client-side by
`frontend/src/utils/localizedContent.ts` (`pickLocalized`) so an already-fetched
record can render correctly when the interface language switches.

## API behaviour

### Merchant endpoints

- `GET/POST/PATCH /api/v1/tenant/categories`
- `GET /api/v1/tenant/categories/tree`
- `GET/POST/PATCH /api/v1/tenant/stores`
- `GET/PATCH /api/v1/tenant/stores/{store}/storefront`

Merchant resources return both raw language columns, so an editor can save one
language without erasing the other:

```json
{
  "name": "Beverages",
  "name_ar": "المشروبات",
  "localized_name": "المشروبات",
  "description": "Soft drinks, juices and water.",
  "description_ar": "مشروبات غازية وعصائر ومياه.",
  "localized_description": "مشروبات غازية وعصائر ومياه.",
  "locale": "ar"
}
```

`localized_name` and `localized_description` are convenience values resolved for
the request language. `StorefrontSettingsResource` adds the same treatment and
returns `settings` with both languages plus `localized_settings` resolved for one
language.

### Public endpoints

- `GET /api/v1/public/storefront/{tenant}/{store}`
- `GET /api/v1/public/storefront/{tenant}/{store}/categories`
- `GET /api/v1/public/storefront/{tenant}/{store}/products`

Public resources return one language only. The resolved copy is placed in the
normal field and the `*_ar` siblings are removed, so a shopper never receives
merchant copy in the other language. The `locale` field says which language was
served.

### Search and the category tree

Category search matches English and Arabic names, descriptions and slugs.
Filtering the tree keeps the ancestors of a matching child, so searching for the
Arabic name of a child still nests it under its parent instead of returning an
orphan. Slugs remain unique per tenant; translated names are not unique.

## Frontend

- `src/lib/axiosClient.ts` sends `Accept-Language: <current locale>` on every
  request.
- Category, store and storefront forms use English/Arabic content tabs. The two
  language fields are independent; switching tabs never clears the other side.
- Business facts stay outside the tabs.
- Lists display the active language through `pickLocalized`, falling back to the
  available copy, and show a small “Arabic missing” badge when a record has no
  Arabic name.
- Client-side category search matches both language columns and retains parent
  paths, mirroring the API.
- Interface direction follows the selected locale (`rtl` for Arabic), while
  localized content fields explicitly render RTL where appropriate.

## Database migration

`2026_11_07_000000_add_localized_content_to_categories_and_stores.php` adds
nullable `name_ar` and `description_ar` columns to `categories` and `stores`.

The migration is additive and non-destructive:

- Existing rows keep their current values.
- No column is renamed, dropped or made required.
- Rolling back drops only the Arabic columns, leaving canonical data intact.

## Seeders

`TenantSeeder` and `CatalogSeeder` are idempotent:

- Tenants, stores, categories, products, variants and images are matched by
  stable identifiers (slug, SKU, email) and updated in place.
- A second run creates no duplicate demo rows.
- Existing category creation timestamps are preserved.
- Merchant-created rows with different slugs are untouched.
- Demo categories and stores carry realistic Arabic names, descriptions and
  storefront copy for all demo workspaces.

`DatabaseSeeder` runs both seeders, so a fresh database is bilingual.

## Tests

Backend:

```bash
cd backend
vendor/bin/phpunit --filter LocalizedShopContentTest
```

The suite covers:

- Arabic and English retrieval, `?locale=` and `Accept-Language`.
- Editing one language without overwriting the other.
- Creating Arabic-only and English-only content.
- Validation errors on Arabic fields.
- Bilingual search and tree ancestry.
- Store and storefront-settings localization.
- Public fallback and removal of raw `*_ar` keys.
- Tenant isolation.
- Seeder idempotency, preserved timestamps and bilingual demo data.

Frontend:

```bash
cd frontend
node --experimental-strip-types --test tests/*.test.ts
npm run type-check
```

`tests/localizedContent.test.ts` covers language switching and per-field fallback
in both directions.

## Adding another language later

The current columns are deliberately explicit (`*_ar`) for the two launched
languages. To add a third language, add a suffix column (for example `name_fr`)
and extend `LocalizedText`, `PreferredLocale`, the Form Requests, the Resources
and the frontend content tabs. The storefront settings document already keeps
translations as sibling keys, so a new language only needs a new suffix in its
`LOCALIZED_FIELDS` list and validation rules.
