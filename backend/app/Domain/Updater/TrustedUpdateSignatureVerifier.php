<?php

namespace App\Domain\Updater;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SodiumException;

/**
 * Verifies detached publisher authenticity; it does not validate ZIP contents.
 * The caller must also match archive SHA-256 to the signed manifest before use.
 */
final class TrustedUpdateSignatureVerifier
{
    /**
     * @param  array<string, string>  $trustedPublicKeys  Publisher key ID => canonical base64 Ed25519 public key
     */
    public function verify(
        UpdatePackageManifest $manifest,
        string $keyId,
        string $signatureBase64,
        array $trustedPublicKeys,
    ): void {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException('Ed25519 signature verification is unavailable on this PHP host.');
        }

        $payload = $this->canonicalPayload($manifest, $keyId);
        $keyBase64 = $trustedPublicKeys[$keyId] ?? null;

        if (! is_string($keyBase64)) {
            throw new InvalidArgumentException('Trusted update publisher is not configured.');
        }

        $publicKey = $this->decodeCanonicalBase64($keyBase64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $signature = $this->decodeCanonicalBase64($signatureBase64, SODIUM_CRYPTO_SIGN_BYTES);

        if ($publicKey === null || $signature === null) {
            throw new InvalidArgumentException('Trusted update publisher signature material is invalid.');
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (SodiumException) {
            $valid = false;
        }

        if (! $valid) {
            throw new InvalidArgumentException('Trusted update publisher signature verification failed.');
        }
    }

    /**
     * Domain-separated, deterministic UTF-8 signing bytes for a future release signer.
     * Both the signer and installer must use the same fixed key order and JSON options.
     */
    public function canonicalPayload(UpdatePackageManifest $manifest, string $keyId): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\z/D', $keyId) !== 1) {
            throw new InvalidArgumentException('Trusted publisher key identifier is invalid.');
        }

        if (! UpdateVersion::isCanonical($manifest->targetVersion)
            || ! UpdateVersion::isCanonical($manifest->minimumCurrentVersion)
            || preg_match('/\A[a-f0-9]{64}\z/D', $manifest->sha256) !== 1) {
            throw new InvalidArgumentException('Signed update manifest fields are not canonical.');
        }

        try {
            $json = json_encode([
                'format' => 'foodex-update-manifest-v1',
                'signer_key_id' => $keyId,
                'target_version' => $manifest->targetVersion,
                'minimum_current_version' => $manifest->minimumCurrentVersion,
                'archive_sha256' => $manifest->sha256,
                'contains_migrations' => $manifest->containsMigrations,
                'release_notes' => $manifest->releaseNotes,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException('Signed update manifest encoding is invalid.');
        }

        return "FOODEX-UPDATE-SIGNATURE-V1\n".$json;
    }

    private function decodeCanonicalBase64(string $encoded, int $expectedBytes): ?string
    {
        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded)
            || strlen($decoded) !== $expectedBytes
            || ! hash_equals(base64_encode($decoded), $encoded)) {
            return null;
        }

        return $decoded;
    }
}
