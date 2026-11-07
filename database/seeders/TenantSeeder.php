<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StoreStatus;
use App\Enums\UserStatus;
use App\Enums\WhatsAppAccountStatus;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Tenancy\TenantService;
use App\Services\Users\UserService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The four demo workspaces: tenant, stores, the merchant's team, a connected
 * WhatsApp account, and the platform operators' memberships.
 *
 * The memberships are what make `admin@gmail.com` appear in the workspace
 * switcher — `/me` returns `user->tenants()`, so a Super Admin with no
 * membership would have nothing to switch to.
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = app(TenantService::class);
        $users = app(UserService::class);
        $context = app(TenantContext::class);

        $merchantRole = Role::query()->where('slug', 'merchant')->firstOrFail();
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        $operators = User::query()
            ->whereIn('email', array_column(DemoPeople::platformUsers(), 'email'))
            ->get();

        foreach (DemoPeople::tenants() as $definition) {
            $tenant = Tenant::query()->where('slug', $definition['slug'])->first();
            $isNewTenant = ! $tenant instanceof Tenant;

            if ($isNewTenant) {
                $tenant = $tenants->createTenant([
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'contact_email' => $definition['contact_email'],
                    'phone' => $definition['phone'],
                    'currency' => 'USD',
                    'timezone' => 'Asia/Gaza',
                    'locale' => $definition['locale'],
                    'store_name' => $definition['stores'][0]['name'],
                ]);
            } else {
                $tenant->forceFill([
                    'name' => $definition['name'],
                    'contact_email' => $definition['contact_email'],
                    'phone' => $definition['phone'],
                    'currency' => 'USD',
                    'timezone' => 'Asia/Gaza',
                    'locale' => $definition['locale'],
                ])->save();
            }

            // A fresh demo tenant is backdated so its reporting windows look
            // lived-in; an existing tenant keeps the age it already has.
            $createdAt = $tenant->created_at ?? now()->subDays(rand(95, 120))->startOfDay();
            if ($isNewTenant) {
                $tenant->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
            }

            // Store, member and WhatsApp rows are tenant scoped, so they are
            // written inside the tenant's context, exactly as the HTTP layer
            // would resolve them.
            $context->run($tenant, function () use ($tenant, $definition, $createdAt, $users, $merchantRole, $staffRole): void {
                $this->stores($tenant, $definition, $createdAt);
                $this->members($tenant, $definition, $createdAt, $users, $merchantRole, $staffRole);
                $this->whatsapp($tenant, $definition, $createdAt);
            });

            foreach ($operators as $operator) {
                $tenant->users()->syncWithoutDetaching([
                    $operator->getKey() => ['is_owner' => false],
                ]);
            }
        }
    }

    /**
     * The first store is created alongside the tenant as its default; the
     * remaining stores are additional branches of the same merchant.
     *
     * @param  array<string, mixed>  $definition
     */
    private function stores(Tenant $tenant, array $definition, Carbon $createdAt): void
    {
        $default = $tenant->stores()->where('is_default', true)->first();

        foreach ($definition['stores'] as $index => $store) {
            $attributes = [
                'name' => $store['name'],
                'name_ar' => $store['name_ar'],
                'slug' => $store['slug'],
                'description' => $store['description'],
                'description_ar' => $store['description_ar'],
                'email' => $store['email'],
                'phone' => $store['phone'],
                'storefront_enabled' => true,
                'storefront_settings' => $store['storefront'],
            ];

            if ($index === 0 && $default !== null) {
                // The default store is born named "default" by
                // TenantService::createDefaultStore(); give it the name,
                // slug and contact details the demo calls it by.
                $default->forceFill($attributes)->save();

                continue;
            }

            // Idempotent by slug: a second run updates the same branch rather
            // than creating a duplicate, and a branch the merchant added with a
            // different slug is never touched.
            $record = Store::query()->firstOrNew(['slug' => $store['slug']]);

            $record->fill($attributes + [
                'status' => StoreStatus::Active,
                'currency' => $tenant->currency,
                'timezone' => $tenant->timezone,
                'is_default' => false,
            ]);

            if (! $record->exists) {
                $record->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt]);
            }

            $record->save();
        }
    }

    /**
     * The merchant and their staff: a real tenant user with a tenant scoped
     * role, never a platform role — platform operators get memberships only.
     *
     * @param  array<string, mixed>  $definition
     */
    private function members(
        Tenant $tenant,
        array $definition,
        Carbon $joinedAt,
        UserService $users,
        Role $merchantRole,
        Role $staffRole,
    ): void {
        foreach ($definition['members'] as $member) {
            // A repeated seed must not create a second account for the same
            // person; their role membership is already in place.
            if (User::query()->where('email', $member['email'])->exists()) {
                continue;
            }

            $user = $users->createForTenant($tenant, $member['role'] === 'merchant' ? $merchantRole : $staffRole, [
                'name' => $member['name'],
                'email' => $member['email'],
                'phone' => $member['phone'],
                'password' => 'password',
                'status' => UserStatus::Active,
                'is_owner' => $member['is_owner'],
            ]);

            $user->forceFill([
                'email_verified_at' => $joinedAt,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
                'last_login_at' => now()->subMinutes(rand(30, 2880)),
            ])->save();
        }
    }

    /**
     * A connected account so the inbox has a channel to display. The
     * credentials are placeholders: Meta never sees them, and the demo never
     * sends a message.
     *
     * @param  array<string, mixed>  $definition
     */
    private function whatsapp(Tenant $tenant, array $definition, Carbon $connectedAt): void
    {
        $account = WhatsappAccount::query()->firstOrNew(['phone_number_id' => 'DEMO_PHONE_NUMBER_ID']);
        $isNewAccount = ! $account->exists;

        $account->fill([
            'store_id' => null,
            'name' => $definition['whatsapp']['name'],
            'phone_number' => $definition['whatsapp']['phone'],
            'business_account_id' => 'DEMO_BUSINESS_ACCOUNT_ID',
            'access_token' => 'DEMO_ACCESS_TOKEN',
            'webhook_verify_token' => 'DEMO_WEBHOOK_VERIFY_TOKEN',
            'status' => WhatsAppAccountStatus::Connected,
            'is_active' => true,
            'connected_at' => $connectedAt,
            'last_synced_at' => now(),
        ]);

        if ($isNewAccount) {
            $account->forceFill(['created_at' => $connectedAt, 'updated_at' => $connectedAt]);
        }

        $account->save();
    }
}
