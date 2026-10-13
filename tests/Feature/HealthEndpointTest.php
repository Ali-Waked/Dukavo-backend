<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_api_status(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'application', 'laravel', 'php', 'environment', 'time'],
            ])
            ->assertJsonPath('data.status', 'ok');
    }

    /**
     * The unauthenticated contract of the real authenticated entry point. Dukavo
     * is a versioned API consumed by an SPA, so there is no `/api/user`
     * scaffold route — its replacement is `/api/v1/me`.
     */
    public function test_the_authenticated_endpoint_refuses_anonymous_json_requests(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_authenticated_endpoint_returns_401_rather_than_redirecting_for_browser_requests(): void
    {
        $this->get('/api/v1/me')->assertStatus(401);
    }
}
