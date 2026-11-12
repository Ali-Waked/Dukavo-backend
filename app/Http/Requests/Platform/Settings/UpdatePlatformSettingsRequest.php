<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform\Settings;

use App\Enums\ConfigurationCategory;
use App\Http\Requests\ApiRequest;
use App\Services\Configuration\ConfigurationRegistry;

/**
 * Validates a partial settings section.
 *
 * The payload is flat: `values` is keyed by the setting's field name (the key
 * without its category prefix), because a dot in a Laravel validation path
 * means "nested array". Sending the full dotted key is still accepted by the
 * service for programmatic callers, but the form contract and the error paths
 * stay flat so the Vue form can display an error next to the right input.
 */
class UpdatePlatformSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = ConfigurationCategory::tryFrom((string) $this->route('category'));

        if ($category === null) {
            return [];
        }

        $mayManageSecrets = (bool) $this->user()?->hasPermissionTo('platform_settings.secrets');

        return app(ConfigurationRegistry::class)->validationRules($category, $mayManageSecrets);
    }
}
