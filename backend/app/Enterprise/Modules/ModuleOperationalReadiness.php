<?php

namespace App\Enterprise\Modules;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Health\ModuleHealthStatus;

/**
 * Version-scoped operational attestation is separate from lifecycle ENABLED.
 * A healthy probe or a broad entitlement cannot certify an unreviewed module.
 */
final class ModuleOperationalReadiness
{
    public function __construct(private readonly ModuleHealthService $health) {}

    public function status(string $code, int $manifestVersion, ModuleState $state): string
    {
        if ($state !== ModuleState::ENABLED) {
            return 'inactive';
        }

        $certifiedVersions = config('enterprise_module_readiness.certified_versions', []);
        if (! is_array($certifiedVersions)
            || ($certifiedVersions[$code] ?? null) !== $manifestVersion) {
            return 'uncertified';
        }

        return $this->health->report($code)->status === ModuleHealthStatus::HEALTHY
            ? 'ready'
            : 'unhealthy';
    }
}
