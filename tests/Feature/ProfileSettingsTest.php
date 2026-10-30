<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * The user's own account: identity, avatar and notification preferences.
 *
 * These endpoints live outside the tenant group on purpose (an account belongs
 * to its owner, not to whichever workspace is selected), so the tests run
 * without an X-Tenant header — and the merchant fixture is the only actor
 * needed.
 */
class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_the_profile_is_the_callers_own_account(): void
    {
        $this->login()
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.name', $this->user->name)
            ->assertJsonPath('data.email', $this->user->email)
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_updating_the_profile_changes_only_the_passed_fields(): void
    {
        $this->login()
            ->patchJson('/api/v1/profile', [
                'name' => 'Claire Updated',
                'phone' => '+971500000000',
                'locale' => 'ar',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Claire Updated')
            ->assertJsonPath('data.phone', '+971500000000')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.email', $this->user->email);

        $user = $this->user->fresh();
        $this->assertSame('Claire Updated', $user->name);
        $this->assertSame('ar', $user->locale);
    }

    public function test_email_and_status_are_not_editable(): void
    {
        $response = $this->login()
            ->patchJson('/api/v1/profile', [
                'email' => 'sneaky@example.test',
                'status' => 'suspended',
            ])
            ->assertOk();

        $this->assertSame($this->user->email, $response->json('data.email'));
        $this->assertSame('active', $response->json('data.status'));
        $this->assertSame($this->user->email, $this->user->fresh()->email);
    }

    public function test_an_invalid_locale_is_rejected(): void
    {
        $this->login()
            ->patchJson('/api/v1/profile', ['locale' => 'xx'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');
    }

    public function test_the_avatar_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');

        $response = $this->login()
            ->post('/api/v1/profile/avatar', ['avatar' => $this->avatarPng()])
            ->assertOk()
            ->assertJsonPath('success', true);

        $avatarUrl = $response->json('data.avatar_url');
        $this->assertNotNull($avatarUrl);

        $avatarPath = $this->user->fresh()->avatar_path;
        $this->assertNotNull($avatarPath);
        Storage::disk('public')->assertExists($avatarPath);

        // The URL reported back must resolve to the stored file.
        $this->assertStringContainsString($avatarPath, $avatarUrl);

        $this->login()
            ->deleteJson('/api/v1/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($avatarPath);
    }

    public function test_the_avatar_rejects_non_images_and_oversized_files(): void
    {
        Storage::fake('public');

        $this->login()
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('notes.txt', 10),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->login()
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('huge.png', 3000, 'image/png'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_avatar_rejects_a_file_that_only_claims_to_be_an_image(): void
    {
        Storage::fake('public');

        $broken = "\x89PNG\r\n\x1a\n".str_repeat('x', 32);

        $this->login()
            ->post('/api/v1/profile/avatar', [
                'avatar' => UploadedFile::fake()->createWithContent('broken.png', $broken),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_the_avatar_removes_the_old_file_only_after_the_new_one_is_saved(): void
    {
        Storage::fake('public');

        $this->login()
            ->post('/api/v1/profile/avatar', ['avatar' => $this->avatarPng('first.png')])
            ->assertOk();

        $firstPath = $this->user->fresh()->avatar_path;
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->login()
            ->post('/api/v1/profile/avatar', ['avatar' => $this->avatarPng('second.png')])
            ->assertOk();

        $secondPath = $this->user->fresh()->avatar_path;
        $this->assertNotNull($secondPath);
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertMissing($firstPath);

        $entry = AuditLog::query()
            ->where('auditable_type', (new User)->getMorphClass())
            ->where('auditable_id', $this->user->getKey())
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($firstPath, $entry->old_values['avatar_path']);
        $this->assertSame($secondPath, $entry->new_values['avatar_path']);
    }

    public function test_a_failed_avatar_save_keeps_the_previous_file_and_removes_the_new_one(): void
    {
        Storage::fake('public');

        $oldPath = 'avatars/'.$this->user->getKey().'/existing.png';
        Storage::disk('public')->put($oldPath, 'existing avatar');
        $this->user->forceFill(['avatar_path' => $oldPath])->save();

        $failure = null;

        DB::statement('PRAGMA query_only = ON');

        try {
            app(ProfileService::class)->storeAvatar(
                $this->user->fresh(),
                $this->avatarPng('replacement.png'),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }

        $this->assertNotNull($failure);
        $this->assertSame($oldPath, $this->user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame(
            [$oldPath],
            Storage::disk('public')->allFiles('avatars/'.$this->user->getKey()),
        );
    }

    public function test_notification_preferences_default_to_sensible_values(): void
    {
        $preferences = $this->login()
            ->getJson('/api/v1/profile/notifications')
            ->assertOk()
            ->json('data');

        $this->assertTrue($preferences['email']['order_updates']);
        $this->assertFalse($preferences['email']['product_news']);
        $this->assertTrue($preferences['whatsapp']['new_messages']);
        $this->assertArrayHasKey('email', $preferences);
        $this->assertArrayHasKey('whatsapp', $preferences);
    }

    public function test_notification_preferences_persist_and_merge_partial_updates(): void
    {
        $this->login()
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['order_updates' => false],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $preferences = $this->login()
            ->getJson('/api/v1/profile/notifications')
            ->assertOk()
            ->json('data');

        $this->assertFalse($preferences['email']['order_updates']);
        $this->assertTrue($preferences['email']['low_stock']);
        $this->assertTrue($preferences['whatsapp']['order_updates']);
    }

    public function test_unknown_preference_keys_are_dropped(): void
    {
        $this->login()
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['silence_everything' => true],
                'whatsapp' => ['spam' => true],
            ])
            ->assertOk();

        $stored = $this->user->fresh()->notification_preferences;

        $this->assertArrayNotHasKey('silence_everything', $stored['email']);
        $this->assertArrayNotHasKey('spam', $stored['whatsapp']);
    }

    public function test_notification_preferences_must_be_booleans(): void
    {
        $this->login()
            ->putJson('/api/v1/profile/notifications', [
                'email' => ['order_updates' => 'sometimes'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email.order_updates');
    }

    /**
     * Return an authed request chain, signing in once and reusing the token.
     *
     * Signing in a second time would be refused: the first login leaves a session
     * cookie behind, so Fortify's `guest` middleware (correctly) redirects a
     * client that is already authenticated. Guards are forgotten before each use
     * so they resolve the caller from the bearer token rather than a cached user.
     */
    private function login(): static
    {
        if ($this->token === null) {
            $this->app['auth']->forgetGuards();

            $this->token = (string) $this->flushHeaders()
                ->postJson('/api/v1/auth/login', [
                    'email' => $this->user->email,
                    'password' => 'password',
                ])
                ->assertOk()
                ->json('data.token');
        }

        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function avatarPng(string $name = 'me.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        ));
    }
}
