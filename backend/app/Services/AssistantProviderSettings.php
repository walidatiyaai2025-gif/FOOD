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
     *   timeout_seconds:int,
     *   api_key_configured:bool,
     *   source:string
     * }
     */
    public function snapshot(): array
    {
        $stored = $this->storedConfig();
        $config = $stored ?? (array) config('assistant.external_ai', []);

        return [
            'enabled' => $this->boolean($config['enabled'] ?? false),
            'provider' => 'openai_compatible',
            'api_url' => trim((string) ($config['api_url'] ?? '')),
            'model' => trim((string) ($config['model'] ?? '')),
            'timeout_seconds' => min(120, max(3, (int) ($config['timeout_seconds'] ?? 20))),
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
     * @param  array{enabled:bool,api_url:string,model:string,timeout_seconds:int}  $config
     */
    public function persist(array $config, ?string $apiKey = null, bool $clearApiKey = false): Setting
    {
        return DB::transaction(function () use ($config, $apiKey, $clearApiKey): Setting {
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

            $setting->forceFill([
                'value' => json_encode([
                    'enabled' => (bool) $config['enabled'],
                    'provider' => 'openai_compatible',
                    'api_url' => trim($config['api_url']),
                    'model' => trim($config['model']),
                    'timeout_seconds' => min(120, max(3, (int) $config['timeout_seconds'])),
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

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
