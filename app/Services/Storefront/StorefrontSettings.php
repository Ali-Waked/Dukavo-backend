<?php

declare(strict_types=1);

namespace App\Services\Storefront;

/**
 * Defaults for the presentation-only storefront settings document.
 *
 * Keeping the shape in one class means the merchant editor, the public payload
 * and the checkout all describe the same fields. Values are deliberately
 * nullable: an empty field means "not provided", never a fabricated default
 * address, phone number or policy.
 */
final class StorefrontSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'tagline' => null,
            'about' => null,
            'hero_image_url' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'whatsapp_number' => null,
            'address' => null,
            'social' => [
                'facebook' => null,
                'instagram' => null,
                'tiktok' => null,
            ],
            'opening_hours' => null,
            'delivery_note' => null,
            'return_policy' => null,
            'show_out_of_stock' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    /**
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
}
