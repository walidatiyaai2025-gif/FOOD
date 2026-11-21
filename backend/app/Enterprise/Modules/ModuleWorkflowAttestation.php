<?php

namespace App\Enterprise\Modules;

use JsonException;
use SodiumException;

/**
 * A workflow attestation must be signed by an explicitly trusted source key.
 *
 * A syntactically valid SHA, Actions URL and list of "passed" strings are
 * insufficient evidence. Verification uses a source-controlled public key;
 * private signing keys must never be shipped with the application. The signer
 * key ID itself is bound to the signature, preventing trusted-key alias replay.
 */
final class ModuleWorkflowAttestation
{
    /**
     * Bind a workflow signature to the complete canonical module contract,
     * including required core, hard/optional dependencies, capabilities,
     * permissions and health-check definitions. A version number alone
     * cannot detect a silently changed manifest.
     */
    public static function manifestSha256(ModuleManifest $manifest): ?string
    {
        try {
            return hash('sha256', json_encode(
                $manifest->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $stages
     * @param  array<string, mixed>  $attestation
     */
    public static function payload(string $moduleCode, int $version, array $stages, array $attestation): ?string
    {
        $keyId = $attestation['key_id'] ?? null;
        $sha = $attestation['head_sha'] ?? null;
        $manifestSha256 = $attestation['manifest_sha256'] ?? null;
        $reviewIssue = $attestation['review_issue'] ?? null;
        $ciRun = $attestation['ci_run'] ?? null;
        $checks = $attestation['checks'] ?? null;

        if ($moduleCode === '' || $version < 1 || ($attestation['version'] ?? null) !== $version
            || ! is_string($keyId) || preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $keyId) !== 1
            || ! is_string($sha) || preg_match('/^[a-f0-9]{40}$/iD', $sha) !== 1
            || ! is_string($manifestSha256) || preg_match('/^[a-f0-9]{64}$/D', $manifestSha256) !== 1
            || ! is_int($reviewIssue) || $reviewIssue < 1
            || ! is_string($ciRun)
            || preg_match('~^https://github\.com/walidatiyaai2025-gif/FOOD/actions/runs/[1-9][0-9]*$~D', $ciRun) !== 1
            || ! array_is_list($stages) || $stages === []
            || ! is_array($checks) || count($checks) !== count($stages)) {
            return null;
        }

        $orderedChecks = [];
        foreach ($stages as $stage) {
            $check = is_string($stage) ? ($checks[$stage] ?? null) : null;
            if (! is_string($stage) || trim($stage) === '' || isset($orderedChecks[$stage])
                || ! is_array($check) || count($check) !== 2
                || ($check['result'] ?? null) !== 'passed'
                || ! is_string($check['test'] ?? null)
                || trim($check['test']) === '') {
                return null;
            }

            $orderedChecks[$stage] = [
                'result' => 'passed',
                'test' => $check['test'],
            ];
        }

        try {
            return json_encode([
                'module_code' => $moduleCode,
                'key_id' => $keyId,
                'version' => $version,
                'head_sha' => $sha,
                'manifest_sha256' => $manifestSha256,
                'review_issue' => $reviewIssue,
                'ci_run' => $ciRun,
                'checks' => $orderedChecks,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $attestation */
    public static function hasTrustedSignature(array $attestation, string $payload): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $keyId = $attestation['key_id'] ?? null;
        $signatureBase64 = $attestation['signature'] ?? null;
        $trusted = config('enterprise_module_readiness.trusted_public_keys', []);

        if (! is_string($keyId)
            || preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $keyId) !== 1
            || ! is_string($signatureBase64) || ! is_array($trusted)
            || ! is_string($trusted[$keyId] ?? null)) {
            return false;
        }

        $publicKey = base64_decode($trusted[$keyId], true);
        $signature = base64_decode($signatureBase64, true);
        if ($publicKey === false || $signature === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (SodiumException) {
            return false;
        }
    }
}
