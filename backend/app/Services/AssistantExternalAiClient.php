<?php

namespace App\Services;

use App\Domain\Assistant\Data\AssistantBrainRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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

    public function __construct(private readonly AssistantProviderSettings $settings) {}

    /**
     * @return array{intent:?string,confidence:float,message:string,entities:array<string,mixed>}|null
     */
    public function route(AssistantBrainRequest $request): ?array
    {
        if (! $this->settings->usable()) {
            return null;
        }

        $config = $this->settings->snapshot();

        try {
            $this->assertSafeEndpoint($config['api_url']);

            $response = $this->request(
                apiKey: $this->settings->apiKey(),
                timeoutSeconds: $config['timeout_seconds'],
            )->post($config['api_url'], [
                'model' => $config['model'],
                'temperature' => 0.1,
                'max_tokens' => 350,
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
            ]);

            if (! $response->successful()) {
                return null;
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            return $this->parseRoutingResponse($content);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param array{api_url:string,model:string,timeout_seconds:int} $config
     * @return array{ok:bool,message:string}
     */
    public function probe(array $config, ?string $apiKey): array
    {
        try {
            $this->assertSafeEndpoint($config['api_url']);

            $response = $this->request(
                apiKey: $apiKey,
                timeoutSeconds: $config['timeout_seconds'],
            )->post($config['api_url'], [
                'model' => $config['model'],
                'temperature' => 0,
                'max_tokens' => 24,
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with exactly: FOODEX_OK'],
                ],
            ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'message' => 'Provider returned HTTP '.$response->status().'.',
                ];
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || trim($content) === '') {
                return [
                    'ok' => false,
                    'message' => 'Provider response did not include choices[0].message.content.',
                ];
            }

            return [
                'ok' => true,
                'message' => 'Connection succeeded.',
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return [
                'ok' => false,
                'message' => 'Connection failed. Verify the URL, model, key, TLS and firewall.',
            ];
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
                'api_url' => ['Use a public HTTP/HTTPS provider endpoint without embedded credentials.'],
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
     * @return array{intent:?string,confidence:float,message:string,entities:array<string,mixed>}|null
     */
    private function parseRoutingResponse(string $content): ?array
    {
        $trimmed = trim($content);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        }

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
- For general conversation or anything outside the allowed intents, set intent to null and answer briefly in {$language} in message.
- Never invent business facts, totals, prices, inventory, customer data or order states.
- Never request or perform write actions.
- entities may only contain obvious values explicitly stated by the user, such as order_id, customer_id, product_id, driver_id, store_id, channel, threshold, query, status, from or to.
- If unsure, use intent null.
PROMPT;
    }
}
