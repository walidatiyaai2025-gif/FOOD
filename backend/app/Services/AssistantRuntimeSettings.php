<?php

namespace App\Services;

use App\Models\Setting;
use Throwable;

final class AssistantRuntimeSettings
{
    public const ENABLED_KEY = 'assistant_enabled';

    /**
     * @return array{
     *     enabled:bool,
     *     configured_enabled:bool,
     *     read_only:bool,
     *     source:string,
     *     storage_available:bool
     * }
     */
    public function snapshot(): array
    {
        $resolved = $this->resolveEnabled();
        $readOnly = $this->readOnly();

        return [
            'enabled' => $resolved['enabled'] && $readOnly,
            'configured_enabled' => $resolved['enabled'],
            'read_only' => $readOnly,
            'source' => $resolved['source'],
            'storage_available' => $resolved['storage_available'],
        ];
    }

    /**
     * Global configured switch. The API separately enforces readOnly() so an
     * unsafe server configuration returns 503 instead of silently enabling V1.
     */
    public function enabled(): bool
    {
        return $this->resolveEnabled()['enabled'];
    }

    public function readOnly(): bool
    {
        return (bool) config('assistant.read_only', true);
    }

    public function persist(bool $enabled): Setting
    {
        $setting = Setting::query()
            ->whereNull('store_id')
            ->where('key', self::ENABLED_KEY)
            ->orderBy('id')
            ->first();

        if (! $setting instanceof Setting) {
            $setting = new Setting([
                'store_id' => null,
                'key' => self::ENABLED_KEY,
            ]);
        }

        $setting->forceFill([
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false,
        ])->save();

        return $setting;
    }

    /** @return array{enabled:bool,source:string,storage_available:bool} */
    private function resolveEnabled(): array
    {
        try {
            $setting = $this->storedSetting();
        } catch (Throwable $exception) {
            report($exception);

            return [
                'enabled' => false,
                'source' => 'fail_closed',
                'storage_available' => false,
            ];
        }

        if (! $setting instanceof Setting) {
            return [
                'enabled' => (bool) config('assistant.enabled', false),
                'source' => 'environment',
                'storage_available' => true,
            ];
        }

        return [
            'enabled' => $this->decodeBoolean($setting->getAttribute('value')),
            'source' => 'dashboard',
            'storage_available' => true,
        ];
    }

    private function storedSetting(): ?Setting
    {
        return Setting::query()
            ->whereNull('store_id')
            ->where('key', self::ENABLED_KEY)
            ->orderByDesc('id')
            ->first();
    }

    private function decodeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return in_array((int) $value, [0, 1], true) && (int) $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_bool($decoded)) {
                return $decoded;
            }

            if (is_int($decoded) && in_array($decoded, [0, 1], true)) {
                return $decoded === 1;
            }

            if (is_string($decoded)) {
                $value = $decoded;
            } else {
                return false;
            }
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => false,
        };
    }
}
