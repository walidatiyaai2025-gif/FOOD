<?php

namespace Tests\Feature;

use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    public function test_health_check_covers_database_and_storage(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.storage', true);
    }

    public function test_correlation_id_is_preserved_in_response(): void
    {
        $this->withHeader('X-Correlation-ID', 'foodex-test-123')
            ->get('/api/v1/version')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'foodex-test-123');
    }

    public function test_health_response_contains_correlation_id_without_internal_details(): void
    {
        $response = $this->get('/api/v1/health')->assertOk();

        $this->assertNotEmpty($response->json('correlation_id'));
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('path', $response->json());
    }
}
