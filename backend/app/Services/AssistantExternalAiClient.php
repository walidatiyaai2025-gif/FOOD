<?php

namespace App\Services;

use App\Domain\Assistant\Data\AssistantBrainRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AssistantExternalAiClient
{
    private const ALLOWED_INTENTS = [
        'sales.summary',
        'sales.compare',
        'orders.summary',
        'orders.lookup',
        'stores.summary',
        'stores.compare',
        'customers.summary',
        'customers.activity',
        'products.performance',
        'orders.late',
        'orders.cancelled',
        'cancellations.summary',
        'drivers.status',
        'drivers.assignments',
        'inventory.alerts',
        'brief.daily',
    ];

    public function __construct(
        private readonly AssistantProviderSettings $settings,
        private readonly AssistantModelRegistry $models,
    ) {}

    /**
     * @return array{intent:?string,confidence:float,message:string,entities:array<string,mixed>}|null
     */
    public function route(AssistantBrainRequest $request): ?array
    {
        if (! $this->settings->usable()) {
            return null;
        }

        $config = $this->settings->snapshot();
        $apiKey = $this->settings->apiKey();

        try {
            $this->assertSafeEndpoint($config['api_url']);

            foreach ($this->models->routingOrder('intent_routing') as $index => $model) {
                $response = $this->sendWithResilience(
                    config: $config,
                    apiKey: $apiKey,
                    model: $model,
                    payload: [
                        'model' => $model,
                        'temperature' => $config['temperature'],
                        'max_tokens' => $config['max_tokens'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $this->routingPrompt($request->locale),
                            ],
                            [
                                'role' => 'user',
                                'content' => $request->message,
                            ],
                        ],
                    ],
                    fallback: $index > 0,
                );

                if (! $response instanceof Response) {
                    continue;
                }

                $content = data_get($response->json(), 'choices.0.message.content');

                if (! is_string($content) || trim($content) === '') {
                    $this->providerTelemetry($config, $model, 'malformed', $response->status(), 0, $index > 0);
                    continue;
                }

                $parsed = $this->parseRoutingResponse($content);

                if ($parsed !== null) {
                    return $parsed;
                }
            }

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array{
     *   api_url:string,
     *   model:string,
     *   timeout_seconds:int,
     *   context_window_tokens?:int,
     *   max_tokens?:int
     * }  $config
     * @return array{
     *   ok:bool,
     *   reachable:bool,
     *   agent_capable:bool,
     *   message:string,
     *   latency_ms:int,
     *   capabilities:array<string,mixed>
     * }
     */
    public function probe(array $config, ?string $apiKey): array
    {
        $snapshot = $this->settings->snapshot();
        $config = array_merge($snapshot, $config);
        $started = microtime(true);

        try {
            $this->assertSafeEndpoint($config['api_url']);

            $chat = $this->request(
                apiKey: $apiKey,
                timeoutSeconds: $config['timeout_seconds'],
            )->post($config['api_url'], [
                'model' => $config['model'],
                'temperature' => 0,
                'max_tokens' => min(128, $config['max_tokens']),
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with exactly: FOODEX_OK'],
                ],
            ]);

            $latency = (int) round((microtime(true) - $started) * 1000);

            if (! $chat->successful()) {
                return $this->probeResult(
                    reachable: false,
                    chat: false,
                    structuredJson: false,
                    toolCalls: false,
                    config: $config,
                    latencyMs: $latency,
                    message: __('assistant_settings.errors.provider_http', ['status' => $chat->status()]),
                );
            }

            $content = data_get($chat->json(), 'choices.0.message.content');
            $chatContract = is_string($content) && $this->normalizeModelContent($content) === 'FOODEX_OK';

            if (! $chatContract) {
                return $this->probeResult(
                    reachable: true,
                    chat: false,
                    structuredJson: false,
                    toolCalls: false,
                    config: $config,
                    latencyMs: $latency,
                    message: __('assistant_settings.errors.unexpected_probe_response'),
                );
            }

            $jsonResponse = $this->request(
                apiKey: $apiKey,
                timeoutSeconds: $config['timeout_seconds'],
            )->post($config['api_url'], [
                'model' => $config['model'],
                'temperature' => 0,
                'max_tokens' => min(128, $config['max_tokens']),
                'messages' => [
                    ['role' => 'user', 'content' => 'Return JSON only: {"ok":true,"contract":"foodex"}'],
                ],
            ]);

            $jsonContent = data_get($jsonResponse->json(), 'choices.0.message.content');
            $jsonDecoded = is_string($jsonContent)
                ? json_decode($this->normalizeModelContent($jsonContent), true)
                : null;
            $structuredJson = $jsonResponse->successful()
                && is_array($jsonDecoded)
                && ($jsonDecoded['ok'] ?? null) === true
                && ($jsonDecoded['contract'] ?? null) === 'foodex';

            $toolResponse = $this->request(
                apiKey: $apiKey,
                timeoutSeconds: $config['timeout_seconds'],
            )->post($config['api_url'], [
                'model' => $config['model'],
                'temperature' => 0,
                'max_tokens' => min(128, $config['max_tokens']),
                'messages' => [
                    ['role' => 'user', 'content' => 'Call the foodex_capability_probe tool with {"ok":true}.'],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'foodex_capability_probe',
                        'description' => 'FOODEX capability probe only.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'ok' => ['type' => 'boolean'],
                            ],
                            'required' => ['ok'],
                            'additionalProperties' => false,
                        ],
                    ],
                ]],
                'tool_choice' => [
                    'type' => 'function',
                    'function' => ['name' => 'foodex_capability_probe'],
                ],
            ]);

            $toolCalls = data_get($toolResponse->json(), 'choices.0.message.tool_calls', []);
            $toolContract = false;

            if ($toolResponse->successful() && is_array($toolCalls)) {
                foreach ($toolCalls as $call) {
                    if (data_get($call, 'function.name') === 'foodex_capability_probe') {
                        $arguments = json_decode((string) data_get($call, 'function.arguments', ''), true);
                        $toolContract = is_array($arguments) && ($arguments['ok'] ?? null) === true;
                        break;
                    }
                }
            }

            $latency = (int) round((microtime(true) - $started) * 1000);

            return $this->probeResult(
                reachable: true,
                chat: true,
                structuredJson: $structuredJson,
                toolCalls: $toolContract,
                config: $config,
                latencyMs: $latency,
                message: __('assistant_settings.test_success'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->providerTelemetry($config, (string) ($config['model'] ?? ''), 'probe_exception', null, 0, false);
            report($exception);

            return $this->probeResult(
                reachable: false,
                chat: false,
                structuredJson: false,
                toolCalls: false,
                config: $config,
                latencyMs: (int) round((microtime(true) - $started) * 1000),
                message: __('assistant_settings.errors.connection_failed'),
            );
        }
    }

    public function assertSafeEndpoint(string $apiUrl): void
    {
        $parts = parse_url(trim($apiUrl));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        $valid = in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && $host !== 'localhost'
            && ! str_ends_with($host, '.localhost');

        if ($valid && filter_var($host, FILTER_VALIDATE_IP)) {
            $valid = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (! $valid) {
            throw ValidationException::withMessages([
                'api_url' => [__('assistant_settings.errors.public_endpoint')],
            ]);
        }
    }

    private function request(?string $apiKey, int $timeoutSeconds): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(min(120, max(3, $timeoutSeconds)))
            ->connectTimeout(min(10, max(2, $timeoutSeconds)))
            ->withoutRedirecting();

        if ($apiKey !== null && trim($apiKey) !== '') {
            $request = $request->withToken(trim($apiKey));
        }

        return $request;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $payload
     */
    private function sendWithResilience(
        array $config,
        ?string $apiKey,
        string $model,
        array $payload,
        bool $fallback,
    ): ?Response {
        $circuitKey = $this->circuitKey($config['api_url'], $model);

        if (Cache::get($circuitKey.'.open', false) === true) {
            $this->providerTelemetry($config, $model, 'circuit_open', null, 0, $fallback);

            return null;
        }

        $attempts = 1 + min(2, max(0, (int) $config['retry_attempts']));
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $started = microtime(true);

            try {
                $response = $this->request($apiKey, (int) $config['timeout_seconds'])
                    ->post($config['api_url'], array_merge($payload, ['model' => $model]));

                $latency = (int) round((microtime(true) - $started) * 1000);
                $lastStatus = $response->status();

                if ($response->successful()) {
                    $this->resetCircuit($circuitKey);
                    $this->providerTelemetry($config, $model, 'success', $lastStatus, $latency, $fallback, $attempt);

                    return $response;
                }

                $this->providerTelemetry($config, $model, 'http_error', $lastStatus, $latency, $fallback, $attempt);

                if ($lastStatus !== 429 && $lastStatus < 500) {
                    break;
                }
            } catch (Throwable $exception) {
                $this->providerTelemetry(
                    $config,
                    $model,
                    'transport_error',
                    null,
                    (int) round((microtime(true) - $started) * 1000),
                    $fallback,
                    $attempt,
                );

                if ($attempt >= $attempts) {
                    report($exception);
                }
            }
        }

        $this->markCircuitFailure($circuitKey, $config);

        return null;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function markCircuitFailure(string $circuitKey, array $config): void
    {
        $failuresKey = $circuitKey.'.failures';
        $failures = (int) Cache::get($failuresKey, 0) + 1;
        Cache::put($failuresKey, $failures, 600);

        if ($failures >= (int) $config['circuit_breaker_failures']) {
            Cache::put($circuitKey.'.open', true, (int) $config['circuit_breaker_seconds']);
        }
    }

    private function resetCircuit(string $circuitKey): void
    {
        Cache::forget($circuitKey.'.failures');
        Cache::forget($circuitKey.'.open');
    }

    private function circuitKey(string $apiUrl, string $model): string
    {
        return 'assistant:provider:circuit:'.hash('sha256', $apiUrl.'|'.$model);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function providerTelemetry(
        array $config,
        string $model,
        string $outcome,
        ?int $status,
        int $latencyMs,
        bool $fallback,
        int $attempt = 1,
    ): void {
        Log::info('assistant.provider.request', [
            'provider' => 'openai_compatible',
            'endpoint' => $this->redactedEndpoint((string) ($config['api_url'] ?? '')),
            'model' => $model,
            'outcome' => $outcome,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'attempt' => $attempt,
            'fallback' => $fallback,
        ]);
    }

    private function redactedEndpoint(string $apiUrl): string
    {
        $parts = parse_url(trim($apiUrl));

        if (! is_array($parts)) {
            return '[invalid-endpoint]';
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        return $scheme !== '' && $host !== ''
            ? $scheme.'://'.$host.$port.$path
            : '[invalid-endpoint]';
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{
     *   ok:bool,
     *   reachable:bool,
     *   agent_capable:bool,
     *   message:string,
     *   latency_ms:int,
     *   capabilities:array<string,mixed>
     * }
     */
    private function probeResult(
        bool $reachable,
        bool $chat,
        bool $structuredJson,
        bool $toolCalls,
        array $config,
        int $latencyMs,
        string $message,
    ): array {
        $contextWindow = (int) ($config['context_window_tokens'] ?? 8192);
        $maxTokens = (int) ($config['max_tokens'] ?? 512);
        $budgetContract = $contextWindow >= 8192 && $maxTokens >= 256;
        $agentCapable = $chat && $structuredJson && $toolCalls && $budgetContract;

        return [
            'ok' => $chat,
            'reachable' => $reachable,
            'agent_capable' => $agentCapable,
            'message' => $message,
            'latency_ms' => $latencyMs,
            'capabilities' => [
                'chat' => $chat,
                'structured_json' => $structuredJson,
                'tool_calls' => $toolCalls,
                'budget_contract' => $budgetContract,
                'context_window_tokens' => $contextWindow,
                'max_output_tokens' => $maxTokens,
                'timeout_seconds' => (int) ($config['timeout_seconds'] ?? 20),
            ],
        ];
    }

    /**
     * @return array{intent:?string,confidence:float,message:string,entities:array<string,mixed>}|null
     */
    private function parseRoutingResponse(string $content): ?array
    {
        $trimmed = $this->normalizeModelContent($content);

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            return null;
        }

        $intent = $decoded['intent'] ?? null;
        if ($intent !== null && (! is_string($intent) || ! in_array($intent, self::ALLOWED_INTENTS, true))) {
            return null;
        }

        $message = is_string($decoded['message'] ?? null) ? trim($decoded['message']) : '';
        $confidence = is_numeric($decoded['confidence'] ?? null)
            ? min(1.0, max(0.0, (float) $decoded['confidence']))
            : ($intent === null ? 0.0 : 0.8);

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'message' => $message,
            'entities' => $this->sanitizeEntities($decoded['entities'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function sanitizeEntities(mixed $entities): array
    {
        if (! is_array($entities)) {
            return [];
        }

        $result = [];

        foreach (['order_id', 'customer_id', 'product_id', 'driver_id', 'store_id', 'threshold'] as $key) {
            $value = filter_var($entities[$key] ?? null, FILTER_VALIDATE_INT);
            if (is_int($value) && $value > 0) {
                $result[$key] = $value;
            }
        }

        $channel = strtolower(trim((string) ($entities['channel'] ?? '')));
        if (in_array($channel, ['b2b', 'b2c'], true)) {
            $result['channel'] = $channel;
        }

        foreach (['query', 'status', 'from', 'to'] as $key) {
            $value = trim((string) ($entities[$key] ?? ''));
            if ($value !== '' && mb_strlen($value) <= 160) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function normalizeModelContent(string $content): string
    {
        $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $normalized = preg_replace('/^\s*(?:<think\b[^>]*>.*?<\/think>\s*)+/is', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if (preg_match('/^```(?:json|text)?\s*(.*?)\s*```$/is', $normalized, $matches) === 1) {
            $normalized = trim($matches[1]);
        }

        return $normalized;
    }

    private function routingPrompt(string $locale): string
    {
        $intentList = implode(', ', self::ALLOWED_INTENTS);
        $language = strtolower($locale) === 'ar' ? 'Arabic' : 'English';

        return <<<PROMPT
You are the intent router for FOODEX Assistant. The server, not you, owns authorization, data access and calculations.
Return JSON only with exactly these keys:
{"intent": string|null, "confidence": number, "message": string, "entities": object}

Allowed intents: {$intentList}

Rules:
- Choose an allowed intent only when the user is asking for that FOODEX business/operations information.
- Return only the final JSON object. Do not include chain-of-thought, analysis, <think> tags, markdown fences or prose outside the JSON object.
- For general conversation or anything outside the allowed intents, set intent to null and answer briefly in {$language} in message.
- Never invent business facts, totals, prices, inventory, customer data or order states.
- Never request or perform write actions.
- entities may only contain obvious values explicitly stated by the user, such as order_id, customer_id, product_id, driver_id, store_id, channel, threshold, query, status, from or to.
- If unsure, use intent null.
PROMPT;
    }
}
