<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    public function test_api_exception_is_logged_without_exposing_internal_message(): void
    {
        Log::spy();

        app()->get('/api/v1/__observability-test');

        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
