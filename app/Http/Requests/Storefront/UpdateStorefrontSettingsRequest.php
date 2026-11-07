<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\ApiRequest;

/**
 * Storefront presentation settings.
 *
 * Nothing here affects money or stock; it only controls what the public shop
 * displays. Every field is optional so the merchant can save one section at a
 * time without resending the rest.
 */
class UpdateStorefrontSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'storefront_enabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings' => ['sometimes', 'array'],
            'settings.tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'settings.tagline_ar' => ['sometimes', 'nullable', 'string', 'max:160'],
            'settings.about' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings.about_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings.hero_image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'settings.contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.address_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.social' => ['sometimes', 'array'],
            'settings.social.facebook' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.social.instagram' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.social.tiktok' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.opening_hours' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.opening_hours_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.delivery_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.delivery_note_ar' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings.return_policy' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings.return_policy_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'settings.show_out_of_stock' => ['sometimes', 'boolean'],
            'settings.meta_title' => ['sometimes', 'nullable', 'string', 'max:180'],
            'settings.meta_title_ar' => ['sometimes', 'nullable', 'string', 'max:180'],
            'settings.meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'settings.meta_description_ar' => ['sometimes', 'nullable', 'string', 'max:320'],
        ];
    }
}
