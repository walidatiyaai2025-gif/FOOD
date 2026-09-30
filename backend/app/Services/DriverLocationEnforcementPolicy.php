<?php

namespace App\Services;

use App\Models\AppVersion;
use App\Models\Setting;

final class DriverLocationEnforcementPolicy
{
    private const MINIMUM_HEARTBEAT_VERSION = '1.0.38';

    private const ENABLED_KEY = 'driver_location_enforcement_enabled';

    private const FRESHNESS_KEY = 'driver_location_freshness_seconds';

    public function enabled(): bool
    {
        $stored = $this->read(self::ENABLED_KEY);

        if ($stored === null) {
            return (bool) config('driver_location.enforcement_default', false);
        }

        if (is_bool($stored)) {
            return $stored;
        }

        if (is_numeric($stored)) {
            return (bool) ((int) $stored);
        }

        return filter_var($stored, FILTER_VALIDATE_BOOL);
    }

    public function freshnessSeconds(): int
    {
        $stored = $this->read(self::FRESHNESS_KEY);
        $value = is_numeric($stored)
            ? (int) $stored
            : (int) config('driver_location.freshness_seconds_default', 90);

        return max(30, min(600, $value));
    }

    /** @return array{ready: bool, minimum_heartbeat_version: string, blockers: list<string>} */
    public function rolloutReadiness(): array
    {
        $blockers = [];

        foreach (['android', 'ios'] as $platform) {
            $policy = AppVersion::query()
                ->where('app', 'driver')
                ->where('platform', $platform)
                ->first();

            if (! $policy instanceof AppVersion) {
                $blockers[] = $platform.'_policy_missing';

                continue;
            }

            if (! $this->versionAtLeast((string) $policy->minimum_supported_version, self::MINIMUM_HEARTBEAT_VERSION)) {
                $blockers[] = $platform.'_minimum_below_'.self::MINIMUM_HEARTBEAT_VERSION;
            }

            if (! $this->versionAtLeast((string) $policy->latest_version, self::MINIMUM_HEARTBEAT_VERSION)) {
                $blockers[] = $platform.'_latest_below_'.self::MINIMUM_HEARTBEAT_VERSION;
            }

            if (trim((string) $policy->store_url) === '') {
                $blockers[] = $platform.'_update_url_missing';
            }
        }

        return [
            'ready' => $blockers === [],
            'minimum_heartbeat_version' => self::MINIMUM_HEARTBEAT_VERSION,
            'blockers' => $blockers,
        ];
    }

    /** @return array{enabled: bool, freshness_seconds: int, environment: string, rollout_ready: bool, rollout_blockers: list<string>} */
    public function snapshot(): array
    {
        $readiness = $this->rolloutReadiness();

        return [
            'enabled' => $this->enabled(),
            'freshness_seconds' => $this->freshnessSeconds(),
            'environment' => $this->environment(),
            'rollout_ready' => $readiness['ready'],
            'rollout_blockers' => $readiness['blockers'],
        ];
    }

    /** @return array{enabled: bool, freshness_seconds: int, environment: string, rollout_ready: bool, rollout_blockers: list<string>} */
    public function persist(bool $enabled, int $freshnessSeconds): array
    {
        $this->write(self::ENABLED_KEY, $enabled);
        $this->write(
            self::FRESHNESS_KEY,
            max(30, min(600, $freshnessSeconds)),
        );

        return $this->snapshot();
    }

    private function read(string $baseKey): mixed
    {
        $value = Setting::query()
            ->whereNull('store_id')
            ->where('key', $this->key($baseKey))
            ->orderByDesc('id')
            ->value('value');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function write(string $baseKey, bool|int $value): void
    {
        $key = $this->key($baseKey);
        $setting = Setting::query()
            ->whereNull('store_id')
            ->where('key', $key)
            ->orderBy('id')
            ->first();

        if (! $setting instanceof Setting) {
            $setting = new Setting([
                'store_id' => null,
                'key' => $key,
            ]);
        }

        $setting->forceFill([
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'is_secret' => false,
        ])->save();
    }

    private function key(string $baseKey): string
    {
        return $baseKey.'.'.$this->environment();
    }

    private function environment(): string
    {
        $environment = strtolower(trim((string) config('driver_location.runtime_environment', 'production')));

        return in_array($environment, ['development', 'staging', 'production'], true)
            ? $environment
            : 'production';
    }

    private function versionAtLeast(string $version, string $minimum): bool
    {
        $version = ltrim(trim($version), 'vV');
        $minimum = ltrim(trim($minimum), 'vV');

        if (
            ! preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)
            || ! preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $minimum)
        ) {
            return false;
        }

        return version_compare($version, $minimum, '>=');
    }
}
