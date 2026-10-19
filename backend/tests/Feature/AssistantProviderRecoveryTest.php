<?php

namespace Tests\Feature;

use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Services\AssistantExternalAiClient;
use App\Services\AssistantProviderSettings;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantProviderRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        config([
            'cache.default' => 'array',
            'assistant.performance.enabled' => true,
            'assistant.performance.provider_global_concurrency' => 4,
            'assistant.performance.provider_lease_seconds' => 120,
        ]);
        Cache::flush();
    }

    public function test_provider_outage_opens_circuit_and_recovery_restores_ai_without_affecting_core_runtime(): void
    {
        app(AssistantProviderSettings::class)->persist([
            'enabled' => true,
            'api_url' => 'https://ai.example.test/v1/chat/completions',
            'model' => 'qwen3:4b',
            'timeout_seconds' => 3,
            'retry_attempts' => 0,
            'circuit_breaker_failures' => 1,
            'circuit_breaker_seconds' => 5,
        ]);

        $attempts = 0;
        Http::fake(static function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('simulated Ollama outage');
            }

            return Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'intent' => 'orders.summary',
                            'confidence' => 0.95,
                            'message' => 'Recovered.',
                            'entities' => [],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200);
        });

        $client = app(AssistantExternalAiClient::class);
        $request = new AssistantBrainRequest(
            message: 'show orders',
            locale: 'en',
            actorUserId: 77,
            correlationId: 'provider-recovery',
            tenantKey: 'tenant-a',
        );

        $this->assertNull($client->route($request));
        $this->assertSame(1, $attempts);

        $this->assertNull($client->route($request));
        $this->assertSame(1, $attempts, 'Open circuit must shed provider work immediately.');

        $this->travel(6)->seconds();

        $result = $client->route($request);

        $this->assertNotNull($result);
        $this->assertSame('orders.summary', $result['intent']);
        $this->assertSame(2, $attempts);
    }
}
