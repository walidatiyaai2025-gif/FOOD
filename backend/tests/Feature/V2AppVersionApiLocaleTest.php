<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2AppVersionApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_policy_returns_localized_404_for_each_mobile_app(): void
    {
        foreach (['customer', 'driver', 'van'] as $app) {
            foreach (['ar-KW,ar;q=0.9,en;q=0.5' => 'ar', 'en-US,en;q=0.9' => 'en'] as $header => $locale) {
                $this->withHeader('Accept-Language', $header)
                    ->getJson("/api/v1/app-version?platform=android&app={$app}&current_version=1.0.0")
                    ->assertNotFound()
                    ->assertExactJson([
                        'message' => __('app_versions.api_not_configured', [], $locale),
                    ]);
            }
        }
    }

    public function test_invalid_version_returns_localized_422_without_exposing_exception_details(): void
    {
        foreach (['customer', 'driver', 'van'] as $app) {
            $this->createPolicy($app);

            foreach (['ar-KW,ar;q=0.9,en;q=0.5' => 'ar', 'en-US,en;q=0.9' => 'en'] as $header => $locale) {
                $this->withHeader('Accept-Language', $header)
                    ->getJson("/api/v1/app-version?platform=android&app={$app}&current_version=banana")
                    ->assertUnprocessable()
                    ->assertExactJson([
                        'message' => __('app_versions.api_invalid_version', [], $locale),
                    ]);
            }
        }
    }

    public function test_valid_policy_contract_and_unknown_language_fallback_are_preserved(): void
    {
        foreach (['customer', 'driver', 'van'] as $app) {
            $this->createPolicy($app);
            $this->withHeader('Accept-Language', 'ar-KW,ar;q=0.9')
                ->getJson("/api/v1/app-version?platform=android&app={$app}&current_version=1.0.0")
                ->assertOk()
                ->assertJson([
                    'latest_version' => '2.0.0',
                    'minimum_supported_version' => '1.5.0',
                    'force_update' => true,
                    'update_required' => true,
                    'status' => 'unsupported',
                ]);
        }

        app()->setLocale('en');
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.8')
            ->getJson('/api/v1/app-version?platform=android&app=customer&current_version=banana')
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => __('app_versions.api_invalid_version', [], 'en'),
            ]);
    }

    private function createPolicy(string $app): void
    {
        AppVersion::query()->create([
            'app' => $app,
            'platform' => 'android',
            'latest_version' => '2.0.0',
            'minimum_supported_version' => '1.5.0',
            'force_update' => false,
            'store_url' => 'https://play.google.com/store/apps/details?id=example',
        ]);
    }
}
