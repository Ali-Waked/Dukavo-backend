<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Services\Identity\CountryCatalog;
use Illuminate\Http\Request;

/**
 * Reads an edge-provided country header without making any outbound call.
 *
 * The value is context, never proof. It is used for session metadata and the
 * security history so a person can recognise an unusual sign-in; authorization
 * never depends on it.
 */
final class RequestCountry
{
    public function __construct(private readonly CountryCatalog $countries) {}

    public function from(Request $request): ?string
    {
        foreach ((array) config('dukavo.identity.geo.country_headers', []) as $header) {
            $candidate = strtoupper(trim((string) $request->header($header, '')));

            if ($this->countries->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
