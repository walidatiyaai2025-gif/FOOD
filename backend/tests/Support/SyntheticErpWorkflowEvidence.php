<?php

namespace Tests\Support;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleWorkflowAttestation;

/**
 * Test-only evidence for fixtures that intentionally exercise ready ERP modules.
 *
 * The values here are synthetic; they must never be copied into deployment
 * configuration or used as actual acceptance / CI attestations.
 */
final class SyntheticErpWorkflowEvidence
{
    public static function install(): void
    {
        $required = config('enterprise_module_readiness.required_workflows', []);
        if (! is_array($required)) {
            return;
        }

        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new \RuntimeException('The sodium extension is required for signed ERP test evidence.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        config()->set('enterprise_module_readiness.trusted_public_keys.synthetic-test', base64_encode(sodium_crypto_sign_publickey($keyPair)));

        $registry = app(ModuleRegistry::class);

        foreach ($required as $code => $steps) {
            if (! is_string($code) || ! is_array($steps) || $steps === []) {
                continue;
            }

            $checks = [];
            foreach ($steps as $step) {
                if (! is_string($step) || $step === '') {
                    continue;
                }

                $checks[$step] = [
                    'result' => 'passed',
                    'test' => 'SyntheticErpWorkflowEvidence::'.$code.'::'.$step,
                ];
            }

            $manifest = $registry->manifest($code);
            $version = $manifest->version;
            $manifestSha256 = ModuleWorkflowAttestation::manifestSha256($manifest);
            if ($manifestSha256 === null) {
                throw new \LogicException('Invalid canonical ERP test manifest.');
            }

            $attestation = [
                'version' => $version,
                'key_id' => 'synthetic-test',
                'head_sha' => str_repeat('a', 40),
                'manifest_sha256' => $manifestSha256,
                'review_issue' => 2858,
                'ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/1',
                'checks' => $checks,
            ];
            $payload = ModuleWorkflowAttestation::payload($code, $version, $steps, $attestation);
            if ($payload === null) {
                throw new \LogicException('Invalid synthetic ERP workflow requirements.');
            }

            $attestation['signature'] = base64_encode(sodium_crypto_sign_detached($payload, $secretKey));
            config()->set('enterprise_module_readiness.workflow_attestations.'.$code, $attestation);
        }
    }
}
