<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\ConfigurationCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Settings\UpdatePlatformSettingsRequest;
use App\Models\User;
use App\Services\Configuration\PlatformConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform configuration sections.
 *
 * The registry decides which keys exist, the request decides whether the shape
 * is valid and this controller decides whether this operator may touch *this*
 * section. A single `platform_settings.update` grant is not enough for the
 * security or maintenance section, and a secret field additionally requires
 * `platform_settings.secrets`. Those checks are server-side; the Vue form only
 * mirrors them to avoid offering a control that will be refused.
 */
class PlatformSettingController extends Controller
{
    public function __construct(private readonly PlatformConfigurationService $settings) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success('', [
            'categories' => $this->settings->catalogue(),
            'permissions' => $this->permissions($request->user()),
        ]);
    }

    public function show(Request $request, string $category): JsonResponse
    {
        $resolved = $this->resolveCategory($category);
        $this->authorise($request->user(), $resolved, false);

        return ApiResponse::success('', [
            'category' => [
                'category' => $resolved->value,
                'label' => $resolved->label(),
                'label_ar' => $resolved->labelAr(),
                'holds_secrets' => $resolved->holdsSecrets(),
                'settings' => $this->settings->forCategory($resolved),
            ],
            'permissions' => $this->permissions($request->user()),
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request, string $category): JsonResponse
    {
        $resolved = $this->resolveCategory($category);

        if ($resolved === ConfigurationCategory::Infrastructure) {
            throw new AuthorizationException('Infrastructure settings are managed by the deployment environment and are read-only here.');
        }

        $this->authorise($request->user(), $resolved, true);

        $updated = $this->settings->updateCategory(
            $resolved,
            (array) $request->validated('values', []),
            $request->user(),
            (bool) $request->user()?->hasPermissionTo('platform_settings.secrets'),
        );

        return ApiResponse::success('Settings saved.', [
            'category' => $resolved->value,
            'settings' => $updated,
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(?User $user): array
    {
        return [
            'view' => (bool) $user?->hasPermissionTo('platform_settings.view'),
            'update' => (bool) $user?->hasPermissionTo('platform_settings.update'),
            'secrets' => (bool) $user?->hasPermissionTo('platform_settings.secrets'),
            'test' => (bool) $user?->hasPermissionTo('platform_settings.test'),
            'security' => (bool) $user?->hasPermissionTo('platform_security.manage'),
            'features' => (bool) $user?->hasPermissionTo('platform_features.manage'),
        ];
    }

    private function resolveCategory(string $category): ConfigurationCategory
    {
        $resolved = ConfigurationCategory::tryFrom($category);

        if ($resolved === null) {
            abort(404, 'Unknown settings category.');
        }

        return $resolved;
    }

    private function authorise(?User $user, ConfigurationCategory $category, bool $write): void
    {
        if ($category === ConfigurationCategory::Security || $category === ConfigurationCategory::Maintenance) {
            $required = $write ? 'platform_security.manage' : 'platform_security.view';

            if (! $user?->hasPermissionTo($required)) {
                throw new AuthorizationException(sprintf('You do not have the "%s" permission.', $required));
            }

            return;
        }

        if ($category === ConfigurationCategory::Features) {
            $required = $write ? 'platform_features.manage' : 'platform_features.view';

            if (! $user?->hasPermissionTo($required)) {
                throw new AuthorizationException(sprintf('You do not have the "%s" permission.', $required));
            }

            return;
        }

        $required = $write ? 'platform_settings.update' : 'platform_settings.view';

        if (! $user?->hasPermissionTo($required)) {
            throw new AuthorizationException(sprintf('You do not have the "%s" permission.', $required));
        }
    }
}
