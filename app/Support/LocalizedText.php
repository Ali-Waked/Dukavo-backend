<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Choosing between an English value and its stored translation.
 *
 * A blank translation is treated as missing, not as an intentional empty
 * article: a half-filled form should show the English copy rather than a page
 * with no title. The fallback is per field, so an article with an Arabic body
 * but no Arabic excerpt still reads correctly.
 */
final class LocalizedText
{
    public static function pick(?string $english, ?string $translated, string $locale, string $language = 'ar'): ?string
    {
        if ($locale === $language && $translated !== null && trim($translated) !== '') {
            return $translated;
        }

        return $english;
    }
}
