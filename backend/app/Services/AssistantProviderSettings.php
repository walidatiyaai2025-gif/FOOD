<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantProviderSettings
{
    public const CONFIG_KEY = 'assistant_external_ai';

    public const API_KEY_KEY = 'assistant_external_ai_api_key';

    /**
     * @return array{
     *   enabled:bool,
     *   provider:string,
     *   api_url:string,
     *   model:string,
     *   fallback_models:array<int,string>,
     *   temperature:float,
     *   max_tokens:int,
     *   context_window_tokens:int,
     *   timeout_seconds:int,
     *   retry_attempts:int,
     *   circuit_breaker_failures:int,
     *   circuit_breaker_seconds:int,
     *   api_key_configured:bool,
     *   source:string
     * }
     */
    public function snapshot(): array
    {
        $stored = $this->storedConfig();
        $defaults = (array) config('assistant.external_ai', []);
        $config = $stored ?? $defaults;

        return [
            'enabled' => $this->boolean($config['enabled'] ?? false),
            'provider' => 'openai_compatible',
            'api_url' => trim((string) ($config['api_url'] ?? '')),
            'model' => trim((string) ($config['model'] ?? '')),
            'fallback_models' => $this->models(
                $config['fallback_models'] ?? $defaults['fallback_models'] ?? [],
                trim((string) ($config['model'] ?? '')),
            ),
            'temperature' => min(1.0, max(0.0, (float) ($config['temperature'] ?? $defaults['temperature'] ?? 0.1))),
            'max_tokens' => min(4096, max(128, (int) ($config['max_tokens'] ?? $defaults['max_tokens'] ?? 512))),
            'context_window_tokens' => min(131072, max(2048, (int) ($config['context_window_tokens'] ?? $defaults['context_window_tokens'] ?? 8192))),
            'timeout_seconds' => min(120, max(3, (int) ($config['timeout_seconds'] ?? 20))),
            'retry_attempts' => min(2, max(0, (int) ($config['retry_attempts'] ?? $defaults['retry_attempts'] ?? 1))),
            'circuit_breaker_failures' => min(10, max(1, (int) ($config['circuit_breaker_failures'] ?? $defaults['circuit_breaker_failures'] ?? 3))),
            'circuit_breaker_seconds' => min(300, max(5, (int) ($config['circuit_breaker_seconds'] ?? $defaults['circuit_breaker_seconds'] ?? 30))),
            'api_key_configured' => $this->apiKey() !== null,
            'source' => $stored === null ? 'environment' : 'dashboard',
        ];
    }

    public function usable(): bool
    {
        $snapshot = $this->snapshot();

        return $snapshot['enabled']
            && $snapshot['api_url'] !== ''
            && $snapshot['model'] !== '';
    }

    /**
     * @return array<int,string>
     */
    public function routingModels(): array
    {
        $snapshot = $this->snapshot();
        $models = array_filter(
            array_merge([$snapshot['model']], $snapshot['fallback_models']),
            static fn (string $model): bool => $model !== '',
        );

        return array_values(array_unique($models));
    }

    public function apiKey(): ?string
    {
        try {
            $setting = Setting::query()
                ->whereNull('store_id')
                ->where('key', self::API_KEY_KEY)
                ->orderByDesc('id')
                ->first();

            if ($setting instanceof Setting && is_string($setting->value) && $setting->value !== '') {
                $storedValue = $setting->value;
                $decodedValue = json_decode($storedValue, true);
                $encryptedValue = is_string($decodedValue) ? $decodedValue : $storedValue;
                $decrypted = Crypt::decryptString($encryptedValue);

                return $decrypted === '' ? null : $decrypted;
            }
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $environment = trim((string) config('assistant.external_ai.api_key', ''));

        return $environment === '' ? null : $environment;
    }

    /**
     * @param  array{
     *   enabled:bool,
     *   api_url:string,
     *   model:string,
     *   timeout_seconds:int,
     *   fallback_models?:array<int,string>|string,
     *   temperature?:float|int|string,
     *   max_tokens?:int|string,
     *   context_window_tokens?:int|string,
     *   retry_attempts?:int|string,
     *   circuit_breaker_failures?:int|string,
     *   circuit_breaker_seconds?:int|string
     * }  $config
     */
    public function persist(array $config, ?string $apiKey = null, bool $clearApiKey = false): Setting
    {
        return DB::transaction(function () use ($config, $apiKey, $clearApiKey): Setting {
            $current = $this->snapshot();
            $setting = Setting::query()
                ->whereNull('store_id')
                ->where('key', self::CONFIG_KEY)
                ->orderBy('id')
                ->first();

            if (! $setting instanceof Setting) {
                $setting = new Setting([
                    'store_id' => null,
                    'key' => self::CONFIG_KEY,
                ]);
            }

            $model = trim($config['model']);

            $setting->forceFill([
                'value' => json_encode([
                    'enabled' => (bool) $config['enabled'],
                    'provider' => 'openai_compatible',
                    'api_url' => trim($config['api_url']),
                    'model' => $model,
                    'fallback_models' => $this->models($config['fallback_models'] ?? $current['fallback_models'], $model),
                    'temperature' => min(1.0, max(0.0, (float) ($config['temperature'] ?? $current['temperature']))),
                    'max_tokens' => min(4096, max(128, (int) ($config['max_tokens'] ?? $current['max_tokens']))),
                    'context_window_tokens' => min(131072, max(2048, (int) ($config['context_window_tokens'] ?? $current['context_window_tokens']))),
                    'timeout_seconds' => min(120, max(3, (int) $config['timeout_seconds'])),
                    'retry_attempts' => min(2, max(0, (int) ($config['retry_attempts'] ?? $current['retry_attempts']))),
                    'circuit_breaker_failures' => min(10, max(1, (int) ($config['circuit_breaker_failures'] ?? $current['circuit_breaker_failures']))),
                    'circuit_breaker_seconds' => min(300, max(5, (int) ($config['circuit_breaker_seconds'] ?? $current['circuit_breaker_seconds']))),
                ], JSON_THROW_ON_ERROR),
                'is_secret' => false,
            ])->save();

            if ($clearApiKey) {
                Setting::query()
                    ->whereNull('store_id')
                    ->where('key', self::API_KEY_KEY)
                    ->delete();
            } elseif ($apiKey !== null && trim($apiKey) !== '') {
                $secret = Setting::query()
                    ->whereNull('store_id')
                    ->where('key', self::API_KEY_KEY)
                    ->orderBy('id')
                    ->first();

                if (! $secret instanceof Setting) {
                    $secret = new Setting([
                        'store_id' => null,
                        'key' => self::API_KEY_KEY,
                    ]);
                }

                $secret->forceFill([
                    'value' => json_encode(
                        Crypt::encryptString(trim($apiKey)),
                        JSON_THROW_ON_ERROR,
                    ),
                    'is_secret' => true,
                ])->save();
            }

            return $setting;
        }, 3);
    }

    /** @return array<string,mixed>|null */
    private function storedConfig(): ?array
    {
        try {
            $setting = Setting::query()
                ->whereNull('store_id')
                ->where('key', self::CONFIG_KEY)
                ->orderByDesc('id')
                ->first();

            if (! $setting instanceof Setting || ! is_string($setting->value)) {
                return null;
            }

            $decoded = json_decode($setting->value, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function models(mixed $value, string $primary = ''): array
    {
        $models = is_string($value) ? explode(',', $value) : (is_array($value) ? $value : []);
        $normalized = [];

        foreach ($models as $model) {
            $model = trim((string) $model);

            if ($model === '' || $model === $primary || in_array($model, $normalized, true)) {
                continue;
            }

            $normalized[] = mb_substr($model, 0, 160);

            if (count($normalized) >= 4) {
                break;
            }
        }

        return $normalized;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
