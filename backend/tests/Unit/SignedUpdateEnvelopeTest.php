<?php

namespace Tests\Unit;

use App\Domain\Updater\SignedUpdateEnvelope;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SignedUpdateEnvelopeTest extends TestCase
{
    public function test_strict_envelope_and_ephemeral_publisher_signature_pass(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP ext-sodium is required.');
        }

        $verifier = new TrustedUpdateSignatureVerifier;
        $keys = sodium_crypto_sign_keypair();
        $manifest = $this->unsignedEnvelope();
        $data = SignedUpdateEnvelope::parse(json_encode($manifest, JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached(
            $verifier->canonicalPayload($data->manifest, $data->keyId),
            sodium_crypto_sign_secretkey($keys),
        );
        $manifest['signature'] = base64_encode($signature);
        $signed = SignedUpdateEnvelope::parse(json_encode($manifest, JSON_THROW_ON_ERROR));
        $signed->verify($verifier, ['owner-key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
        $this->assertSame('99.0.0', $signed->manifest->targetVersion);

        $modified = $manifest;
        $modified['contains_migrations'] = true;
        $this->expectException(InvalidArgumentException::class);
        SignedUpdateEnvelope::parse(json_encode($modified, JSON_THROW_ON_ERROR))
            ->verify($verifier, ['owner-key' => base64_encode(sodium_crypto_sign_publickey($keys))]);
    }

    public function test_rejects_unknown_fields_invalid_types_and_unsigned_sidecars(): void
    {
        $valid = $this->unsignedEnvelope();
        $cases = [
            ['format' => 'unsupported'] + array_diff_key($valid, ['format' => true]),
            array_diff_key($valid, ['signature' => true]),
            array_merge($valid, ['archive_sha256' => 123]),
            array_merge($valid, ['contains_migrations' => 'false']),
            array_merge($valid, ['extra_unsigned_payload' => 'data']),
        ];
        foreach ($cases as $payload) {
            try {
                SignedUpdateEnvelope::parse(json_encode($payload, JSON_THROW_ON_ERROR));
                $this->fail('Invalid signed envelope was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        SignedUpdateEnvelope::parse(str_repeat('x', 16385));
    }

    /** @return array<string, mixed> */
    private function unsignedEnvelope(): array
    {
        return [
            'format' => 'foodex-signed-update-v1',
            'signer_key_id' => 'owner-key',
            'target_version' => '99.0.0',
            'minimum_current_version' => '0.0.0',
            'archive_sha256' => str_repeat('a', 64),
            'contains_migrations' => false,
            'release_notes' => 'Unit-test only, not a signed production release',
            'signature' => '',
        ];
    }
}
