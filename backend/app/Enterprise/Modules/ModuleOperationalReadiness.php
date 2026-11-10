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

        // Gated evidence is separate from version certification and health.
        // Every ERP workflow must have a reviewed, version-scoped CI proof;
        // never infer operational readiness from ENABLED or healthy probes.
        if (! $this->hasWorkflowEvidence($code, $manifestVersion)) {
            return 'uncertified';
        }

        return $this->health->report($code)->status === ModuleHealthStatus::HEALTHY
            ? 'ready'
            : 'unhealthy';
    }

    private function hasWorkflowEvidence(string $code, int $manifestVersion): bool
    {
        $category = config('enterprise_modules.modules.'.$code.'.category');
        $required = config('enterprise_module_readiness.required_workflows', []);

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

        $sha = $attestation['head_sha'] ?? null;
        $reviewIssue = $attestation['review_issue'] ?? null;
        $ciRun = $attestation['ci_run'] ?? null;
        if (! is_string($sha) || preg_match('/^[a-f0-9]{40}$/i', $sha) !== 1
            || ! is_int($reviewIssue) || $reviewIssue < 1
            || ! is_string($ciRun)
            || preg_match('~^https://github\\.com/walidatiyaai2025-gif/FOOD/actions/runs/[1-9][0-9]*$~', $ciRun) !== 1) {
            return false;
        }

        $checks = $attestation['checks'] ?? null;
        if (! is_array($checks)) {
            return false;
        }

        foreach ($steps as $step) {
            if (! is_string($step) || $step === '' || ! is_array($checks[$step] ?? null)) {
                return false;
            }

            $check = $checks[$step];
            if (($check['result'] ?? null) !== 'passed'
                || ! is_string($check['test'] ?? null)
                || trim($check['test']) === '') {
                return false;
            }
        }

        return true;
    }
}
