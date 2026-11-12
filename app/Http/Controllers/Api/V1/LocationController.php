<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Identity\CityCatalog;
use App\Services\Identity\CountryCatalog;
use App\Services\Identity\TimezoneCatalog;
use App\Support\ApiResponse;
use App\Support\PreferredLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Country, city and timezone data for personal selectors.
 *
 * The country list and timezone list are server-owned; the SPA and the mobile
 * app never carry their own copies that could drift. City suggestions come from
 * a per-country file, so searching the UAE does not parse the US city index.
 *
 * Cities are suggestions, not an exhaustive address database. The selector
 * always accepts a manually typed city, which is why this endpoint never claims
 * a value is authoritative.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly CountryCatalog $countries,
        private readonly CityCatalog $cities,
        private readonly TimezoneCatalog $timezones,
    ) {}

    public function countries(Request $request): JsonResponse
    {
        $locale = PreferredLocale::from($request);

        return ApiResponse::success('Countries loaded.', [
            'default' => $this->countries->default(),
            'countries' => $this->countries->options($locale),
        ]);
    }

    public function cities(Request $request, string $country): JsonResponse
    {
        $country = strtoupper($country);

        if (! $this->countries->exists($country)) {
            abort(404, 'The requested country was not found.');
        }

        $query = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        $limit = (int) min(max((int) $request->query('limit', 30), 1), 100);

        return ApiResponse::success('Cities loaded.', [
            'country' => $this->countries->meta($country, PreferredLocale::from($request)),
            'query' => $query,
            'cities' => $this->cities->search($country, $query, $limit),
            'manual_entry' => true,
        ]);
    }

    public function timezones(): JsonResponse
    {
        return ApiResponse::success('Timezones loaded.', [
            'fallback' => TimezoneCatalog::FALLBACK,
            'timezones' => $this->timezones->options(),
        ]);
    }
}
