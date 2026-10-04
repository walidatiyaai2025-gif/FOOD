<?php

namespace App\Services;

use App\Models\Setting;

final class AssistantRuntimeSettings
{
    public const ENABLED_KEY = 'assistant_enabled';

    /** @return array{enabled:bool,read_only:bool,source:string} */
    public function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'read_only' => $this->readOnly(),
            'source' => $this->storedSetting() instanceof Setting ? 'dashboard' : 'environment',
        ];
    }

    public function enabled(): bool
    {
        $setting = $this->storedSetting();

        if (! $setting instanceof Setting) {
            return (bool) config('assistant.enabled', false);
        }

        return $this->decodeBoolean($setting->getAttribute('value'));
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
