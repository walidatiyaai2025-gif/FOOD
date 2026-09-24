<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationSmokeTest extends TestCase
{
    public function test_health_endpoint_boots(): void
    {
        $this->get('/health')->assertOk();
    }

    public function test_api_version_endpoint_reports_runtime_contract(): void
    {
        $expectedVersion = trim((string) file_get_contents(base_path('../VERSION')));

        $this->get('/api/v1/version')
            ->assertOk()
            ->assertJson([
                'api' => 'v1',
                'platform_version' => $expectedVersion,
            ]);
    }

    public function test_root_runtime_identity_matches_platform_baseline(): void
    {
        $expectedVersion = trim((string) file_get_contents(base_path('../VERSION')));

        $this->get('/')
            ->assertOk()
            ->assertJson([
                'name' => 'FOODEX',
                'phase' => 'bootstrap',
                'version' => $expectedVersion,
            ]);
    }

    public function test_runtime_defaults_are_arabic_first_with_english_fallback(): void
    {
        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_installer_foundation_is_available_before_lock(): void
    {
        $this->get('/install')->assertOk()->assertSee('FOODEX Setup Wizard');
    }
}
