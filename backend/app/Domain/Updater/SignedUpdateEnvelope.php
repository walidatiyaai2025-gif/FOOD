<?php

namespace App\Domain\Updater;

use InvalidArgumentException;
use JsonException;

/**
 * A signed manifest is a separate, publisher-issued sidecar of the ZIP.
 * The archive SHA-256 is signed; embedding a signature inside the archive
 * would produce a self-referential hash.
 */
final readonly class SignedUpdateEnvelope
{
    public function __construct(
        public UpdatePackageManifest $manifest,
        public string $keyId,
        public string $signature,
    ) {}

    public static function parse(string $bytes): self
    {
        if ($bytes === '' || strlen($bytes) > 16384) {
            throw new InvalidArgumentException('Signed update manifest size is invalid.');
        }

        try {
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Signed update manifest is not valid JSON.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Signed update manifest has invalid structure.');
        }

        $required = [
            'format',
            'signer_key_id',
            'target_version',
            'minimum_current_version',
            'archive_sha256',
            'contains_migrations',
            'release_notes',
            'signature',
        ];
        $keys = array_keys($data);
        sort($keys);
        sort($required);
        if ($keys !== $required || $data['format'] !== 'foodex-signed-update-v1') {
            throw new InvalidArgumentException('Signed update manifest format is unsupported.');
        }

        foreach (['signer_key_id', 'target_version', 'minimum_current_version', 'archive_sha256', 'release_notes', 'signature'] as $key) {
            if (! is_string($data[$key])) {
                throw new InvalidArgumentException('Signed update manifest field type is invalid.');
            }
        }

        if (! is_bool($data['contains_migrations'])
            || strlen($data['release_notes']) > 5000
            || strlen($data['signature']) > 128) {
            throw new InvalidArgumentException('Signed update manifest field value is invalid.');
        }

        $manifest = new UpdatePackageManifest(
            $data['target_version'],
            $data['minimum_current_version'],
            $data['archive_sha256'],
            $data['release_notes'],
            $data['contains_migrations'],
        );

        return new self($manifest, $data['signer_key_id'], $data['signature']);
    }

    /**
     * @param  array<string, string>  $trustedPublicKeys
     */
    public function verify(TrustedUpdateSignatureVerifier $verifier, array $trustedPublicKeys): void
    {
        $verifier->verify($this->manifest, $this->keyId, $this->signature, $trustedPublicKeys);
    }
}
