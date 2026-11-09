<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Database\Factories\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery price for one store, optionally narrowed to a city and a set of
 * areas inside that city.
 *
 * A NULL `city` means the zone covers every city the store serves. A non-empty
 * `areas` list limits the zone to those named areas; an empty list means the
 * whole city. Fees are always resolved on the server from this table, never
 * accepted from a checkout payload.
 *
 * The zone is also the customer-facing shipping option. Its prose fields have
 * an English canonical column and per-language overrides in `translations`, so
 * translating a zone never duplicates the price rule or its coverage.
 */
#[Fillable([
    'tenant_id',
    'store_id',
    'name',
    'description',
    'method_name',
    'method_description',
    'delivery_instructions',
    'terms',
    'translations',
    'city',
    'areas',
    'fee',
    'free_shipping_threshold',
    'is_active',
    'sort_order',
])]
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasTranslations;

    /** The locale the canonical columns are written in. */
    public const CANONICAL_LOCALE = 'en';

    /**
     * Fields that may be translated. Prices, coverage and flags are not here.
     *
     * @var list<string>
     */
    public const TRANSLATABLE_FIELDS = [
        'name',
        'description',
        'method_name',
        'method_description',
        'delivery_instructions',
        'terms',
    ];

    /**
     * @return list<string>
     */
    protected function auditAttributes(): array
    {
        return [
            'name',
            'description',
            'method_name',
            'method_description',
            'delivery_instructions',
            'terms',
            'translations',
            'city',
            'areas',
            'fee',
            'free_shipping_threshold',
            'is_active',
            'sort_order',
        ];
    }

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'areas' => 'array',
            'fee' => 'decimal:4',
            'free_shipping_threshold' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The language the store prefers when the shopper's language has no copy.
     *
     * Stores inherit their language from the tenant; there is no per-store
     * locale column. Falling back to the configured platform default keeps a
     * detached factory model usable without a database round trip.
     */
    public function storeDefaultLocale(): string
    {
        $store = $this->relationLoaded('store') ? $this->getRelation('store') : $this->store()->first();
        $locale = $store instanceof Store ? $store->tenant?->locale : null;

        if (is_string($locale) && trim($locale) !== '') {
            return strtolower(trim($locale));
        }

        return (string) config('dukavo.locales.default', self::CANONICAL_LOCALE);
    }

    /**
     * One translated field, resolved with the agreed fallback order:
     *
     *   selected language -> store default -> any available translation
     *   -> canonical English -> null
     *
     * A blank value is missing, not an intentional empty field, so a partly
     * translated zone still renders readable copy per field.
     */
    public function localizedField(string $field, string $locale, ?string $defaultLocale = null): ?string
    {
        if (! in_array($field, self::TRANSLATABLE_FIELDS, true)) {
            return null;
        }

        $candidates = [$locale, $defaultLocale ?? $this->storeDefaultLocale()];

        foreach ((array) config('dukavo.locales.supported', [self::CANONICAL_LOCALE]) as $supported) {
            $candidates[] = (string) $supported;
        }

        $candidates[] = (string) config('dukavo.locales.default', self::CANONICAL_LOCALE);
        $candidates[] = self::CANONICAL_LOCALE;

        $seen = [];

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate === '' || isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $value = $this->valueForLocale($field, $candidate);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * All customer-facing copy in one language. The method name falls back to
     * the zone name so a customer always has a label for the delivery option.
     *
     * @return array<string, string|null>
     */
    public function localizedCopy(string $locale, ?string $defaultLocale = null): array
    {
        $copy = [];

        foreach (self::TRANSLATABLE_FIELDS as $field) {
            $copy[$field] = $this->localizedField($field, $locale, $defaultLocale);
        }

        if ($copy['method_name'] === null) {
            $copy['method_name'] = $copy['name'];
        }

        return $copy;
    }

    /**
     * The raw per-language document, limited to supported locales and known
     * fields. Used by the merchant editor; never shown to shoppers verbatim.
     *
     * @return array<string, array<string, string|null>>
     */
    public function translationDocument(): array
    {
        $translations = $this->translations;
        $supported = array_map(
            static fn (mixed $locale): string => strtolower((string) $locale),
            (array) config('dukavo.locales.supported', [self::CANONICAL_LOCALE]),
        );

        if (! is_array($translations)) {
            return [];
        }

        $document = [];

        foreach ($translations as $locale => $fields) {
            $locale = strtolower(trim((string) $locale));

            if ($locale === '' || $locale === self::CANONICAL_LOCALE || ! in_array($locale, $supported, true) || ! is_array($fields)) {
                continue;
            }

            foreach (self::TRANSLATABLE_FIELDS as $field) {
                if (! array_key_exists($field, $fields)) {
                    continue;
                }

                $value = $fields[$field];
                $document[$locale][$field] = is_string($value) && trim($value) !== '' ? $value : null;
            }
        }

        return $document;
    }

    private function valueForLocale(string $field, string $locale): ?string
    {
        // A merchant's raw JSON edit is authoritative for that language, so it
        // is read before the review ledger. The ledger is the overlay for every
        // other language, including the canonical one when the source was not
        // English.
        $translations = $this->translations;
        $jsonValue = is_array($translations) && is_array($translations[$locale] ?? null)
            ? ($translations[$locale][$field] ?? null)
            : null;

        if ($locale === 'ar' && is_string($jsonValue) && trim($jsonValue) !== '') {
            return $jsonValue;
        }

        $approved = $this->translatedValue($field, $locale);

        if ($approved !== null) {
            return $approved;
        }

        if ($locale === self::CANONICAL_LOCALE) {
            $value = $this->getAttribute($field);

            return is_string($value) && trim($value) !== '' ? $value : null;
        }

        return is_string($jsonValue) && trim($jsonValue) !== '' ? $jsonValue : null;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
