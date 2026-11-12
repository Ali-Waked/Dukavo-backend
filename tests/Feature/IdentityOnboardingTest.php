<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-login preference detection and the one-shot onboarding flow.
 *
 * The contract this covers is deliberately conservative: a new account is shown
 * what the platform *thinks* it knows, with a source and confidence, and may
 * confirm, edit or skip. Nothing here may block access, and a second visit must
 * never replay the prompt or overwrite a value the person already set.
 */
class IdentityOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($this->tenant, 'merchant');
    }

    public function test_a_new_account_is_asked_once(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/onboarding')
            ->assertOk()
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonStructure([
                'data' => [
                    'detected' => [
                        'country', 'city', 'timezone', 'locale', 'theme',
                        'currency', 'date_format', 'time_format', 'phone_country_code',
                    ],
                ],
            ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding/skip')
            ->assertOk()
            ->assertJsonPath('data.required', false);

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/v1/profile/onboarding')
            ->assertOk()
            ->assertJsonPath('data.required', false);
    }

    public function test_skipping_never_blocks_the_profile_or_changes_values(): void
    {
        $before = $this->user->fresh()->only(['country_code', 'city', 'timezone', 'theme']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding/skip')
            ->assertOk();

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.name', $this->user->name);

        $this->assertSame($before, $this->user->fresh()->only(['country_code', 'city', 'timezone', 'theme']));
        $this->assertNotNull($this->user->fresh()->preferences_initialized_at);
    }

    public function test_network_location_is_reported_as_an_estimate_with_source_and_confidence(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders([
                'CF-IPCountry' => 'AE',
                'CF-IPCity' => 'Dubai',
                'X-Timezone' => 'Asia/Dubai',
                'Accept-Language' => 'ar-AE,ar;q=0.9,en;q=0.8',
            ])
            ->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/onboarding')
            ->assertOk();

        $response
            ->assertJsonPath('data.detected.country.value', 'AE')
            ->assertJsonPath('data.detected.country.source', 'ip')
            ->assertJsonPath('data.detected.country.country.iso2', 'AE')
            ->assertJsonPath('data.detected.city.value', 'Dubai')
            ->assertJsonPath('data.detected.city.source', 'ip')
            ->assertJsonPath('data.detected.timezone.value', 'Asia/Dubai')
            ->assertJsonPath('data.detected.timezone.source', 'device')
            ->assertJsonPath('data.detected.locale.value', 'ar')
            ->assertJsonPath('data.detected.locale.source', 'browser')
            ->assertJsonPath('data.detected.currency.value', 'AED')
            ->assertJsonPath('data.detected.currency.source', 'ip')
            ->assertJsonPath('data.detected.theme.value', 'system')
            ->assertJsonPath('data.detected.theme.source', 'default');

        // An estimate is never presented as verified information.
        $confidence = $response->json('data.detected.country.confidence');
        $this->assertIsInt($confidence);
        $this->assertGreaterThan(0, $confidence);
        $this->assertLessThan(100, $confidence);
    }

    public function test_a_city_estimate_is_discarded_when_it_disagrees_with_the_country(): void
    {
        $this->user->forceFill([
            'country_code' => 'SA',
            'city' => null,
        ])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders([
                'CF-IPCountry' => 'AE',
                'CF-IPCity' => 'Dubai',
            ])
            ->actingAs($this->user->fresh(), 'sanctum')
            ->getJson('/api/v1/profile/onboarding')
            ->assertOk()
            ->assertJsonPath('data.detected.country.value', 'SA')
            ->assertJsonPath('data.detected.country.source', 'existing')
            ->assertJsonPath('data.detected.city.value', null);
    }

    public function test_device_timezone_is_validated_and_falls_back_to_utc(): void
    {
        $this->withHeaders(['X-Timezone' => 'Mars/Olympus'])
            ->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/onboarding')
            ->assertOk()
            ->assertJsonPath('data.detected.timezone.value', 'UTC')
            ->assertJsonPath('data.detected.timezone.source', 'default');
    }

    public function test_completing_marks_edited_values_manual_and_confirmed_values_with_their_detected_source(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders([
                'CF-IPCountry' => 'AE',
                'CF-IPCity' => 'Dubai',
                'X-Timezone' => 'Asia/Dubai',
                'Accept-Language' => 'ar',
            ])
            ->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'country_code' => 'AE',
                'city' => 'Dubai',
                'timezone' => 'Asia/Dubai',
                'locale' => 'ar',
                'theme' => 'dark',
                'preferred_currency' => 'AED',
            ])
            ->assertOk()
            ->assertJsonPath('data.location.country_code', 'AE')
            ->assertJsonPath('data.location.city', 'Dubai')
            ->assertJsonPath('data.display.theme', 'dark');

        $user = $this->user->fresh();
        $this->assertNotNull($user->preferences_initialized_at);
        $this->assertSame('AE', $user->country_code);
        $this->assertSame('Dubai', $user->city);
        $this->assertSame('Asia/Dubai', $user->timezone);
        $this->assertSame('ar', $user->locale);
        $this->assertSame('dark', $user->theme->value);
        $this->assertSame('AED', $user->preferred_currency);

        $sources = $user->preferences_source;

        // Confirmed detections keep their honest provenance...
        $this->assertSame('ip', $sources['country_code']['source']);
        $this->assertSame('ip', $sources['city']['source']);
        $this->assertSame('device', $sources['timezone']['source']);
        $this->assertSame('browser', $sources['locale']['source']);
        $this->assertSame('ip', $sources['preferred_currency']['source']);

        // ...while a value the person changed is clearly their own choice.
        $this->assertSame('manual', $sources['theme']['source']);
        $this->assertSame(100, $sources['theme']['confidence']);
    }

    public function test_completing_twice_does_not_replay_or_overwrite(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'country_code' => 'AE',
                'city' => 'Dubai',
            ])
            ->assertOk();

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'country_code' => 'US',
                'city' => 'New York',
            ])
            ->assertOk();

        $user = $this->user->fresh();
        $this->assertSame('AE', $user->country_code);
        $this->assertSame('Dubai', $user->city);
    }

    public function test_existing_values_are_not_overwritten_when_they_are_not_submitted(): void
    {
        $this->user->forceFill([
            'country_code' => 'SA',
            'city' => 'Riyadh',
            'timezone' => 'Asia/Riyadh',
            'preferred_currency' => 'SAR',
            'theme' => 'light',
        ])->save();

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'bio' => 'I sell coffee.',
            ])
            ->assertOk();

        $user = $this->user->fresh();
        $this->assertSame('SA', $user->country_code);
        $this->assertSame('Riyadh', $user->city);
        $this->assertSame('Asia/Riyadh', $user->timezone);
        $this->assertSame('SAR', $user->preferred_currency);
        $this->assertSame('light', $user->theme->value);
        $this->assertSame('I sell coffee.', $user->bio);
        $this->assertNotNull($user->preferences_initialized_at);
    }

    public function test_invalid_values_are_rejected_without_initializing_the_account(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'timezone' => 'Not/AZone',
                'country_code' => 'ZZ',
                'preferred_currency' => 'XYZ',
                'theme' => 'neon',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'timezone', 'country_code', 'preferred_currency', 'theme',
            ]);

        $this->assertNull($this->user->fresh()->preferences_initialized_at);
    }

    public function test_phone_country_is_an_iso_country_not_a_dial_code(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'country_code' => 'AE',
                'phone' => '500000000',
                'phone_country_code' => '+971',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_country_code');

        $this->actingAs($this->user->fresh(), 'sanctum')
            ->postJson('/api/v1/profile/onboarding', [
                'country_code' => 'AE',
                'phone' => '500000000',
                'phone_country_code' => 'AE',
            ])
            ->assertOk()
            ->assertJsonPath('data.phone_country_code', 'AE')
            ->assertJsonPath('data.phone_country.iso2', 'AE')
            ->assertJsonPath('data.phone_country.dial_code', '+971');
    }
}
