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
 */
class ShippingService
{
    /**
     * @return Collection<int, ShippingZone>
     */
    public function forStore(Store $store, bool $activeOnly = false): Collection
    {
        return ShippingZone::query()
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
        return ShippingZone::query()->create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->getKey(),
            'name' => $attributes['name'],
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
