<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\UpdateStorefrontSettingsRequest;
use App\Http\Resources\Storefront\StorefrontSettingsResource;
use App\Models\Store;
use App\Services\Storefront\StorefrontSettings;

/**
 * Merchant management of the public shopfront.
 *
 * The store is resolved through the tenant-scoped route binding, so a merchant
 * can never configure another tenant's store.
 */
class StorefrontSettingsController extends Controller
{
    public function show(Store $store): StorefrontSettingsResource
    {
        return new StorefrontSettingsResource($store);
    }

    public function update(UpdateStorefrontSettingsRequest $request, Store $store): StorefrontSettingsResource
    {
        $data = $request->validated();
        $submittedSettings = $data['settings'] ?? null;
        unset($data['settings']);

        if (is_array($submittedSettings)) {
            $data['storefront_settings'] = array_replace_recursive(
                StorefrontSettings::merge($store->storefront_settings),
                $submittedSettings,
            );
        }

        $store->fill($data)->save();

        return new StorefrontSettingsResource($store->refresh());
    }
}
