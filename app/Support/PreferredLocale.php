<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The language a public read should be answered in.
 *
 * An explicit `?locale=` wins so a language switcher is not at the mercy of a
 * browser header, then the usual `Accept-Language` hint. Only languages the
 * platform actually publishes are returned; anything else degrades to the
 * configured default rather than to a 404 or an empty document.
 */
final class PreferredLocale
{
    public static function from(Request $request): string
    {
        $supported = (array) config('dukavo.locales.supported', ['en']);
        $default = (string) config('dukavo.locales.default', 'en');

        $explicit = strtolower(trim((string) $request->query('locale', '')));

        if (in_array($explicit, $supported, true)) {
            return $explicit;
        }

        $header = (string) $request->header('Accept-Language', '');
        $first = trim(explode(',', $header)[0] ?? '');
        $tag = strtolower(trim(explode(';', $first)[0] ?? ''));
        $language = explode('-', $tag)[0];

        return in_array($language, $supported, true) ? $language : $default;
    }
}
