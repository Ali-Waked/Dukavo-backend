<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Support\LocalizedText;

/**
 * Defaults for the presentation-only storefront settings document.
 *
 * Keeping the shape in one class means the merchant editor, the public payload
 * and the checkout all describe the same fields. Values are deliberately
 * nullable: an empty field means "not provided", never a fabricated default
 * address, phone number or policy.
 *
 * Copy fields carry an optional `_ar` sibling. `merge()` keeps the raw document
 * so the merchant editor can show both languages side by side; `localized()`
 * resolves it to a single language for the public shop and drops the sibling
 * keys, so a shopper never receives untranslated merchant copy.
 */
final class StorefrontSettings
{
    /**
     * Copy that has a translation. Contact details, URLs and switches are
     * facts, not prose, and are never localized.
     *
     * @var list<string>
     */
    private const array LOCALIZED_FIELDS = [
        'tagline',
        'about',
        'address',
        'opening_hours',
        'delivery_note',
        'return_policy',
        'meta_title',
        'meta_description',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'tagline' => null,
            'tagline_ar' => null,
            'about' => null,
            'about_ar' => null,
            'hero_image_url' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'whatsapp_number' => null,
            'address' => null,
            'address_ar' => null,
            'social' => [
                'facebook' => null,
                'instagram' => null,
                'tiktok' => null,
            ],
            'opening_hours' => null,
            'opening_hours_ar' => null,
            'delivery_note' => null,
            'delivery_note_ar' => null,
            'return_policy' => null,
            'return_policy_ar' => null,
            'show_out_of_stock' => true,
            'meta_title' => null,
            'meta_title_ar' => null,
            'meta_description' => null,
            'meta_description_ar' => null,
        ];
    }

    /**
     * The raw settings document, including both language columns. This is what
     * the merchant editor receives so saving one language cannot erase the
     * other.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function merge(?array $settings): array
    {
        $merged = array_replace_recursive(self::defaults(), $settings ?? []);

        // A stored `social` value that is not an array would otherwise leak
        // straight into the API response.
        if (! is_array($merged['social'] ?? null)) {
            $merged['social'] = self::defaults()['social'];
        }

        return $merged;
    }

    /**
     * Resolve the document for a public read in one language.
     *
     * Each field falls back independently: an Arabic tagline with no Arabic
     * about text still renders the English about text rather than an empty
     * section. The `_ar` siblings are removed from the result because the
     * shopper should only ever see copy they can read.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function localized(?array $settings, string $locale): array
    {
        $merged = self::merge($settings);

        foreach (self::LOCALIZED_FIELDS as $field) {
            $merged[$field] = LocalizedText::pick(
                self::stringOrNull($merged[$field] ?? null),
                self::stringOrNull($merged[$field.'_ar'] ?? null),
                $locale,
            );

            unset($merged[$field.'_ar']);
        }

        return $merged;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
