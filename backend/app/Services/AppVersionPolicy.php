<?php

namespace App\Services;

use App\Models\AppVersion;
use InvalidArgumentException;

final class AppVersionPolicy
{
    public function evaluate(AppVersion $policy, string $currentVersion): array
    {
        $current = $this->normalize($currentVersion);
        $minimum = $this->normalize((string) $policy->minimum_supported_version);
        $latest = $this->normalize((string) $policy->latest_version);

        if (version_compare($current, $minimum, '<')) {
            $status = 'unsupported';
            $updateRequired = true;
            $forceUpdate = true;
        } elseif (version_compare($current, $latest, '<')) {
            $status = $policy->force_update ? 'forced' : 'optional';
            $updateRequired = true;
            $forceUpdate = (bool) $policy->force_update;
        } else {
            $status = 'current';
            $updateRequired = false;
            $forceUpdate = false;
        }

        return [
            'latest_version' => (string) $policy->latest_version,
            'minimum_supported_version' => (string) $policy->minimum_supported_version,
            'force_update' => $forceUpdate,
            'update_required' => $updateRequired,
            'status' => $status,
            'store_url' => $policy->store_url,
            'release_notes' => $policy->release_notes,
        ];
    }

    public function assertPolicyOrder(string $minimum, string $latest): void
    {
        if (version_compare($this->normalize($minimum), $this->normalize($latest), '>')) {
            throw new InvalidArgumentException('Minimum supported version cannot exceed latest version.');
        }
    }

    private function normalize(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        if (! preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new InvalidArgumentException('Invalid application version.');
        }

        return $version;
    }
}
