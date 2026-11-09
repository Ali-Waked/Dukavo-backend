<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Exceptions\BusinessRuleViolation;
use App\Models\ShippingZone;
use App\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Delivery pricing.
 *
 * The client may say where it wants the order delivered, but the fee is always
 * read from the store's own zone table. A client-supplied fee is never used.
 *
 * Copy is stored separately from the rules: English in the canonical columns,
 * every other language in `translations`. Saving one language never clears
 * another, which is what lets a merchant fill in Arabic over several visits.
 */
class ShippingService
{
    /**
     * @var list<string>
     */
    private const COPY_FIELDS = [
        'name',
        'description',
        'method_name',
        'method_description',
        'delivery_instructions',
        'terms',
    ];

    /**
     * The `_ar` names used by the rest of the shop content API. They are
     * accepted and returned as aliases so shipping follows the same contract.
     *
     * @var array<string, string>
     */
    private const ARABIC_ALIASES = [
        'name_ar' => 'name',
        'description_ar' => 'description',
        'method_name_ar' => 'method_name',
        'method_description_ar' => 'method_description',
        'delivery_instructions_ar' => 'delivery_instructions',
        'terms_ar' => 'terms',
    ];

    /**
     * @return Collection<int, ShippingZone>
     */
    public function forStore(Store $store, bool $activeOnly = false): Collection
    {
        return ShippingZone::query()
            ->with('store.tenant')
            ->where('store_id', $store->getKey())
            ->when($activeOnly, fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Store $store, array $attributes): ShippingZone
    {
        $translations = $this->translationsFromPayload($attributes, []);

        return ShippingZone::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->getKey(),
            'name' => $attributes['name'],
            'description' => $this->nullableText($attributes['description'] ?? null),
            'method_name' => $this->nullableText($attributes['method_name'] ?? null),
            'method_description' => $this->nullableText($attributes['method_description'] ?? null),
            'delivery_instructions' => $this->nullableText($attributes['delivery_instructions'] ?? null),
            'terms' => $this->nullableText($attributes['terms'] ?? null),
            'translations' => $translations === [] ? null : $translations,
            'city' => $this->normaliseNullable($attributes['city'] ?? null),
            'areas' => $this->normaliseAreas($attributes['areas'] ?? null),
            'fee' => round((float) ($attributes['fee'] ?? 0), 2),
            'free_shipping_threshold' => $this->decimalOrNull($attributes['free_shipping_threshold'] ?? null),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'sort_order' => (int) ($attributes['sort_order'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ShippingZone $zone, array $attributes): ShippingZone
    {
        $hasTranslations = array_key_exists('translations', $attributes)
            || $this->hasArabicAlias($attributes);

        if ($hasTranslations) {
            $attributes['translations'] = $this->translationsFromPayload(
                $attributes,
                $zone->translationDocument(),
            ) ?: null;
        }

        foreach (array_keys(self::ARABIC_ALIASES) as $alias) {
            unset($attributes[$alias]);
        }

        foreach (self::COPY_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->nullableText($attributes[$field]);
            }
        }

        if (array_key_exists('city', $attributes)) {
            $attributes['city'] = $this->normaliseNullable($attributes['city']);
        }

        if (array_key_exists('areas', $attributes)) {
            $attributes['areas'] = $this->normaliseAreas($attributes['areas']);
        }

        if (array_key_exists('fee', $attributes)) {
            $attributes['fee'] = round((float) $attributes['fee'], 2);
        }

        if (array_key_exists('free_shipping_threshold', $attributes)) {
            $attributes['free_shipping_threshold'] = $this->decimalOrNull($attributes['free_shipping_threshold']);
        }

        $zone->fill($attributes)->save();

        return $zone;
    }

    public function delete(ShippingZone $zone): void
    {
        // Orders keep the fee they were charged as part of their totals; the
        // foreign key nulls only the pointer back to the deleted rule.
        $zone->delete();
    }

    /**
     * Resolve the delivery fee for an address and a merchandise total.
     *
     * @return array{zone: ShippingZone|null, fee: float, free_shipping: bool, merchandise_total: float}
     */
    public function quote(Store $store, ?string $city, ?string $area, float $merchandiseTotal): array
    {
        $merchandiseTotal = round($merchandiseTotal, 2);
        $zones = $this->forStore($store, activeOnly: true);

        if ($zones->isEmpty()) {
            // A store that has configured no delivery pricing does not charge
            // for delivery. This is explicit rather than an invented fee.
            return [
                'zone' => null,
                'fee' => 0.0,
                'free_shipping' => false,
                'merchandise_total' => $merchandiseTotal,
            ];
        }

        $normalisedCity = $this->normalise($city);
        $normalisedArea = $this->normalise($area);

        $zone = $zones->first(function (ShippingZone $candidate) use ($normalisedCity, $normalisedArea): bool {
            $zoneCity = $this->normalise($candidate->city);

            if ($zoneCity !== null && $zoneCity !== $normalisedCity) {
                return false;
            }

            $areas = $candidate->areas ?? [];

            if ($areas === []) {
                return true;
            }

            // Stored areas may predate the normalising service (seeders,
            // factories, direct database edits), so compare them normalised
            // rather than trusting the case they were written with.
            $areas = array_values(array_filter(array_map(
                fn (mixed $area): ?string => $this->normalise(is_string($area) ? $area : null),
                $areas,
            )));

            return $normalisedArea !== null && in_array($normalisedArea, $areas, true);
        });

        if (! $zone instanceof ShippingZone) {
            throw BusinessRuleViolation::because(
                $normalisedCity === null
                    ? 'Choose a delivery city so we can calculate the delivery fee.'
                    : 'We do not deliver to this address yet. Please choose another city.'
            );
        }

        $fee = round((float) $zone->fee, 2);
        $threshold = $zone->free_shipping_threshold;
        $freeShipping = $threshold !== null && $merchandiseTotal >= (float) $threshold;

        return [
            'zone' => $zone,
            'fee' => $freeShipping ? 0.0 : $fee,
            'free_shipping' => $freeShipping,
            'merchandise_total' => $merchandiseTotal,
        ];
    }

    /**
     * Build the next translation document from a payload.
     *
     * A PATCH is merged field by field against what is already stored, so
     * editing the English name or clearing one Arabic field cannot erase an
     * unrelated translation. Explicit `null` clears a field.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, string|null>>  $existing
     * @return array<string, array<string, string>>
     */
    private function translationsFromPayload(array $attributes, array $existing): array
    {
        $document = $existing;
        $supported = array_map(
            static fn (mixed $locale): string => strtolower((string) $locale),
            (array) config('dukavo.locales.supported', ['en', 'ar']),
        );

        $provided = $attributes['translations'] ?? null;

        if (is_array($provided)) {
            foreach ($provided as $locale => $fields) {
                $locale = strtolower(trim((string) $locale));

                if ($locale === '' || $locale === ShippingZone::CANONICAL_LOCALE || ! in_array($locale, $supported, true) || ! is_array($fields)) {
                    continue;
                }

                foreach (self::COPY_FIELDS as $field) {
                    if (array_key_exists($field, $fields)) {
                        $document[$locale][$field] = $this->nullableText($fields[$field]);
                    }
                }
            }
        }

        foreach (self::ARABIC_ALIASES as $alias => $field) {
            if (array_key_exists($alias, $attributes)) {
                $document['ar'][$field] = $this->nullableText($attributes[$alias]);
            }
        }

        return $this->cleanTranslations($document);
    }

    /**
     * Drop blank fields and empty locales so the stored document is honest
     * about what has actually been translated.
     *
     * @param  array<string, array<string, string|null>>  $document
     * @return array<string, array<string, string>>
     */
    private function cleanTranslations(array $document): array
    {
        $clean = [];

        foreach ($document as $locale => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            foreach (self::COPY_FIELDS as $field) {
                if (! array_key_exists($field, $fields)) {
                    continue;
                }

                $value = $this->nullableText($fields[$field]);

                if ($value !== null) {
                    $clean[$locale][$field] = $value;
                }
            }
        }

        return array_filter($clean, static fn (array $fields): bool => $fields !== []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasArabicAlias(array $attributes): bool
    {
        foreach (array_keys(self::ARABIC_ALIASES) as $alias) {
            if (array_key_exists($alias, $attributes)) {
                return true;
            }
        }

        return false;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, mixed>|null  $areas
     * @return list<string>|null
     */
    private function normaliseAreas(?array $areas): ?array
    {
        if ($areas === null) {
            return null;
        }

        $normalised = [];

        foreach ($areas as $area) {
            $value = $this->normalise(is_string($area) ? $area : null);

            if ($value !== null) {
                $normalised[] = $value;
            }
        }

        return $normalised === [] ? null : array_values(array_unique($normalised));
    }

    private function normaliseNullable(mixed $value): ?string
    {
        return $this->normalise(is_string($value) ? $value : null);
    }

    private function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::lower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
