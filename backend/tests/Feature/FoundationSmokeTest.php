<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationSmokeTest extends TestCase
{
    public function test_health_endpoint_boots(): void
    {
        $this->get('/health')->assertOk();
    }

    public function test_api_version_endpoint_boots(): void
    {
        $this->get('/api/v1/version')->assertOk()->assertJsonPath('api','v1');
    }

    public function test_installer_foundation_is_available_before_lock(): void
    {
        $this->get('/install')->assertOk()->assertSee('FOODEX Setup Wizard');
    }
}
