<?php

namespace App\Enterprise\Modules;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Health\ModuleHealthStatus;
use Throwable;

/**
 * Version-scoped operational attestation is separate from lifecycle ENABLED.
 * A healthy probe or a broad entitlement cannot certify an unreviewed module.
 */
final class ModuleOperationalReadiness
{
    public function __construct(
        private readonly ModuleHealthService $health,
        private readonly ModuleManifestCatalog $manifests,
        private readonly ModuleRegistry $registry,
    ) {}

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

        // Gated evidence is separate from version certification and health.
        // Every ERP workflow must have a reviewed, version-scoped CI proof;
        // never infer operational readiness from ENABLED or healthy probes.
        if (! $this->hasWorkflowEvidence($code, $manifestVersion)) {
            return 'uncertified';
        }

        $health = $this->health->report($code);
        if ($health->status !== ModuleHealthStatus::HEALTHY) {
            return 'unhealthy';
        }

        try {
            $requiredChecks = $this->manifests->require($code)->healthChecks;
        } catch (Throwable) {
            return 'unhealthy';
        }

        // Empty/unconfigured probes are not a successful execution of the
        // health checks bound into an exact-version signed module manifest.
        // In particular Procurement declares 'database' and must really
        // execute that check before its operational HTTP link becomes ready.
        $passedChecks = [];
        foreach ($health->checks as $check) {
            if ($check->status === ModuleHealthStatus::HEALTHY && ! $check->isStale()) {
                $passedChecks[] = $check->key;
            }
        }

        return array_diff($requiredChecks, $passedChecks) === []
            ? 'ready'
            : 'unhealthy';
    }

    private function hasWorkflowEvidence(string $code, int $manifestVersion): bool
    {
        $category = config('enterprise_modules.modules.'.$code.'.category');
        $required = config('enterprise_module_readiness.required_workflows', []);

        // A missing, malformed or unreviewed manifest category must not
        // downgrade an ERP module to optional and evade workflow proof.
        if (! is_string($category) || ! in_array($category, [
            'operations', 'finance', 'analytics', 'platform', 'restaurant',
        ], true)) {
            return false;
        }

        // Newly registered ERP modules must fail closed until a complete
        // workflow matrix is explicitly declared. Non-ERP modules retain
        // their independent version/health readiness contract.
        $erpCategory = in_array($category, ['operations', 'finance', 'analytics'], true);
        if (! is_array($required)) {
            return false;
        }

        if (! $erpCategory && ! array_key_exists($code, $required)) {
            return true;
        }

        $steps = $required[$code] ?? null;
        if (! is_array($steps) || $steps === []) {
            return false;
        }

        $attestations = config('enterprise_module_readiness.workflow_attestations', []);
        $attestation = is_array($attestations) ? ($attestations[$code] ?? null) : null;
        if (! is_array($attestation) || ($attestation['version'] ?? null) !== $manifestVersion) {
            return false;
        }

        try {
            $manifest = $this->manifests->require($code);
        } catch (Throwable) {
            // A missing/malformed canonical manifest is never an approval.
            return false;
        }

        if ($manifest->version !== $manifestVersion) {
            return false;
        }

        $expectedDigest = ModuleWorkflowAttestation::manifestSha256($manifest);
        $signedDigest = $attestation['manifest_sha256'] ?? null;
        if ($expectedDigest === null
            || ! is_string($signedDigest)
            || ! hash_equals($expectedDigest, $signedDigest)) {
            return false;
        }

        // The persisted installation must match the current source manifest
        // and its version. A stale installation row cannot reuse a signed
        // certificate for another manifest merely because ENABLED remains set.
        try {
            $registration = $this->registry->registration($code);
            // Never trust only the caller-supplied lifecycle snapshot.
            // A concurrent admin may have suspended/disabled the persisted
            // ERP module after that snapshot while its attestation remains
            // cryptographically valid for the unchanged manifest version.
            if ($registration === null
                || $registration->manifest_version !== $manifestVersion
                || $registration->getRawOriginal('state') !== ModuleState::ENABLED->value) {
                return false;
            }

            // Model property metadata may describe the storage column as
            // JSON text; read the cast value, then validate its real type.
            $storedManifest = $registration->getAttribute('manifest');
            if (! is_array($storedManifest)) {
                return false;
            }

            $installedManifest = ModuleManifest::fromArray($storedManifest);
            $installedDigest = ModuleWorkflowAttestation::manifestSha256($installedManifest);
            if ($installedManifest->code !== $code
                || $installedManifest->version !== $manifestVersion
                || $installedDigest === null
                || ! hash_equals($expectedDigest, $installedDigest)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $payload = ModuleWorkflowAttestation::payload($code, $manifestVersion, $steps, $attestation);

        return $payload !== null && ModuleWorkflowAttestation::hasTrustedSignature($attestation, $payload);
    }
}
