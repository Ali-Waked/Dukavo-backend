<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Facades\File;

/**
 * City suggestions for the searchable city selector.
 *
 * Cities are split into one file per country so a request for Dubai does not
 * parse the 12,000 US place names. The dataset is a maintained public city
 * index, not a claim that every locality is present; the selector therefore
 * always permits manual entry. Matching is case-insensitive and prioritises
 * prefix matches so "Dub" finds Dubai before "Dublin" when searching the UAE.
 */
final class CityCatalog
{
    /** @var array<string, list<string>> */
    private array $loaded = [];

    /**
     * @return list<string>
     */
    public function forCountry(?string $iso2): array
    {
        $iso2 = strtoupper(trim((string) $iso2));

        if (strlen($iso2) !== 2) {
            return [];
        }

        if (isset($this->loaded[$iso2])) {
            return $this->loaded[$iso2];
        }

        $path = resource_path('data/cities/'.$iso2.'.json');

        if (! File::exists($path)) {
            return $this->loaded[$iso2] = [];
        }

        $decoded = json_decode((string) File::get($path), true);
        $cities = is_array($decoded)
            ? array_values(array_filter(array_map(static fn ($city): string => trim((string) $city), $decoded)))
            : [];

        return $this->loaded[$iso2] = $cities;
    }

    /**
     * Search one country's suggestion set.
     *
     * @return list<string>
     */
    public function search(?string $iso2, string $query = '', int $limit = 30): array
    {
        $limit = max(1, min($limit, 100));
        $query = trim($query);
        $cities = $this->forCountry($iso2);

        if ($cities === []) {
            return [];
        }

        if ($query === '') {
            return array_slice($cities, 0, $limit);
        }

        $needle = mb_strtolower($query);
        $startsWith = [];
        $contains = [];

        foreach ($cities as $city) {
            $haystack = mb_strtolower($city);
            $position = mb_strpos($haystack, $needle);

            if ($position === false) {
                continue;
            }

            if ($position === 0) {
                $startsWith[] = $city;
            } else {
                $contains[] = $city;
            }

            if (count($startsWith) >= $limit) {
                break;
            }
        }

        return array_slice([...$startsWith, ...$contains], 0, $limit);
    }
}
