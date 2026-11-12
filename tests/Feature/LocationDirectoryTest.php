<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Country, city and timezone data for the personal selectors.
 *
 * The server owns these lists so the SPA and the mobile app cannot drift from
 * each other. Cities are suggestions with manual entry always allowed; the
 * endpoints never claim a city list is an address database.
 */
class LocationDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        /** @var Tenant $tenant */
        $tenant = $this->tenantWithStore();
        $this->user = $this->memberOf($tenant, 'merchant');
    }

    public function test_countries_are_localised_and_carry_search_fields(): void
    {
        $english = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries')
            ->assertOk()
            ->json('data.countries');

        $uae = $this->findCountry($english, 'AE');
        $this->assertSame('United Arab Emirates', $uae['name']);
        $this->assertSame('+971', $uae['dial_code']);
        $this->assertSame('AED', $uae['currency']);
        $this->assertSame('🇦🇪', $uae['flag']);
        $this->assertArrayHasKey('native', $uae);
        $this->assertArrayHasKey('name_ar', $uae);
        $this->assertArrayHasKey('cities_count', $uae);

        $arabic = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries?locale=ar')
            ->assertOk()
            ->json('data.countries');

        $this->assertSame('الإمارات', $this->findCountry($arabic, 'AE')['name']);
        $this->assertSame('United Arab Emirates', $this->findCountry($arabic, 'AE')['name_en']);
    }

    public function test_countries_include_iso_native_and_dial_code_for_searching(): void
    {
        $countries = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries')
            ->assertOk()
            ->json('data.countries');

        // Every row exposes the three independent search keys the selector uses.
        foreach (['AE', 'SA', 'US'] as $iso2) {
            $country = $this->findCountry($countries, $iso2);
            $this->assertSame($iso2, $country['iso2']);
            $this->assertNotSame('', $country['native']);
            $this->assertMatchesRegularExpression('/^\+\d+$/', $country['dial_code']);
        }
    }

    public function test_cities_are_scoped_to_the_requested_country_and_searchable(): void
    {
        $payload = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries/AE/cities?q=Dub')
            ->assertOk()
            ->assertJsonPath('data.country.iso2', 'AE')
            ->assertJsonPath('data.query', 'Dub')
            ->assertJsonPath('data.manual_entry', true)
            ->json('data');

        $this->assertNotEmpty($payload['cities']);
        $this->assertContains('Dubai', $payload['cities']);
        $this->assertTrue(
            collect($payload['cities'])->every(fn (string $city): bool => str_contains(strtolower($city), 'dub')),
        );
    }

    public function test_city_results_are_limited_and_prefix_matches_come_first(): void
    {
        $payload = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries/GB/cities?q=lon&limit=5')
            ->assertOk()
            ->json('data');

        $this->assertLessThanOrEqual(5, count($payload['cities']));
        $this->assertSame('London', $payload['cities'][0]);
    }

    public function test_an_empty_query_returns_a_bounded_default_list(): void
    {
        $payload = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries/AE/cities')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($payload['cities']);
        $this->assertLessThanOrEqual(30, count($payload['cities']));
    }

    public function test_an_unknown_country_is_a_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/countries/ZZ/cities')
            ->assertNotFound();
    }

    public function test_timezones_are_iana_identifiers_with_a_utc_fallback(): void
    {
        $payload = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/profile/locations/timezones')
            ->assertOk()
            ->assertJsonPath('data.fallback', 'UTC')
            ->json('data');

        $values = array_column($payload['timezones'], 'value');

        $this->assertContains('Asia/Dubai', $values);
        $this->assertContains('UTC', $values);
        $this->assertCount(count($values), array_unique($values));
    }

    /**
     * @param  list<array<string, mixed>>  $countries
     * @return array<string, mixed>
     */
    private function findCountry(array $countries, string $iso2): array
    {
        $match = collect($countries)->firstWhere('iso2', $iso2);

        $this->assertIsArray($match, "Country {$iso2} was not in the catalogue.");

        return $match;
    }
}
