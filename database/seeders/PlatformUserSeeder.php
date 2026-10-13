<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Services\Users\UserService;
use Database\Seeders\Support\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * The people who work for Dukavo itself rather than for a merchant.
 *
 * `admin@gmail.com` is the login the demo is built around: a Super Admin whose
 * memberships of every workspace are granted by {@see TenantSeeder}, so the
 * account can both administer the platform and open any workspace from the
 * switcher. Nothing here is referenced by tests.
 */
class PlatformUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = app(UserService::class);

        foreach (DemoPeople::platformUsers() as $definition) {
            $role = Role::query()->where('slug', $definition['role'])->firstOrFail();

            $user = $users->createPlatformUser($role, [
                'name' => $definition['name'],
                'email' => $definition['email'],
                'phone' => $definition['phone'],
                'password' => 'password',
                'status' => UserStatus::Active,
                'locale' => 'en',
            ]);

            // The platform predates every demo workspace, and a verified,
            // recently signed-in account is what makes `admin@gmail.com` a
            // usable demo login straight after seeding.
            $joinedAt = now()->subDays(rand(140, 180));

            $user->forceFill([
                'email_verified_at' => $joinedAt,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
                'last_login_at' => now()->subMinutes(rand(20, 600)),
            ])->save();
        }
    }
}
