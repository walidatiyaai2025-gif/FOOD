<?php

namespace Tests\Unit;

use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdatePackageManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TrustedUpdateSignatureVerifierTest extends TestCase
{
    private string $secretKey;

    private string $publicKeyBase64;

    private TrustedUpdateSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP ext-sodium is required for trusted ZIP signature tests.');
        }

        $keys = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keys);
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($keys));
        $this->verifier = new TrustedUpdateSignatureVerifier;
    }

    public function test_signed_manifest_passes_with_an_explicitly_pinned_publisher(): void
    {
        $manifest = $this->manifest();
        $signature = $this->sign($manifest);

        $this->verifier->verify($manifest, 'foodex-publisher-2026', $signature, [
            'foodex-publisher-2026' => $this->publicKeyBase64,
        ]);

        $this->assertStringStartsWith('FOODEX-UPDATE-SIGNATURE-V1', $this->verifier->canonicalPayload($manifest, 'foodex-publisher-2026'));
    }

    public function test_each_signed_field_is_bound_to_the_trusted_signature(): void
    {
        $manifest = $this->manifest();
        $signature = $this->sign($manifest);
        $trusted = ['foodex-publisher-2026' => $this->publicKeyBase64];

        foreach ([
            new UpdatePackageManifest('1.2.4', '1.2.0', str_repeat('a', 64), 'تحديث آمن / Safe update', true),
            new UpdatePackageManifest('1.2.3', '1.1.9', str_repeat('a', 64), 'تحديث آمن / Safe update', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('b', 64), 'تحديث آمن / Safe update', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('a', 64), 'Different release notes', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('a', 64), 'تحديث آمن / Safe update', false),
        ] as $tampered) {
            $this->assertDenied(fn () => $this->verifier->verify($tampered, 'foodex-publisher-2026', $signature, $trusted));
        }
    }

    public function test_no_signer_is_implicitly_trusted_and_wrong_key_is_denied(): void
    {
        $manifest = $this->manifest();
        $signature = $this->sign($manifest);

        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'foodex-publisher-2026', $signature, []));
        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'unknown-signer', $signature, [
            'foodex-publisher-2026' => $this->publicKeyBase64,
        ]));

        $anotherPublisher = sodium_crypto_sign_keypair();
        $otherPublicKey = base64_encode(sodium_crypto_sign_publickey($anotherPublisher));
        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'foodex-publisher-2026', $signature, [
            'foodex-publisher-2026' => $otherPublicKey,
        ]));
    }

    public function test_signature_is_bound_to_publisher_key_identifier_and_domain(): void
    {
        $manifest = $this->manifest();
        $signature = $this->sign($manifest);
        $trusted = [
            'foodex-publisher-2026' => $this->publicKeyBase64,
            'new-publisher' => $this->publicKeyBase64,
        ];

        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'new-publisher', $signature, $trusted));

        $wrongDomain = base64_encode(sodium_crypto_sign_detached('OTHER-SYSTEM'.substr($this->verifier->canonicalPayload($manifest, 'foodex-publisher-2026'), 6), $this->secretKey));
        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'foodex-publisher-2026', $wrongDomain, $trusted));
    }

    public function test_malformed_or_noncanonical_signature_and_key_material_is_denied(): void
    {
        $manifest = $this->manifest();
        $signature = $this->sign($manifest);
        $trusted = ['foodex-publisher-2026' => $this->publicKeyBase64];

        foreach (['', 'garbage!', 'YWJj', $signature."\n", substr($signature, 0, -2)] as $invalid) {
            $this->assertDenied(fn () => $this->verifier->verify($manifest, 'foodex-publisher-2026', $invalid, $trusted));
        }

        $this->assertDenied(fn () => $this->verifier->verify($manifest, 'foodex-publisher-2026', $signature, [
            'foodex-publisher-2026' => base64_encode('wrong length'),
        ]));
        $this->assertDenied(fn () => $this->verifier->verify($manifest, '../unsafe-key', $signature, $trusted));
    }

    public function test_noncanonical_version_checksum_or_invalid_utf8_notes_are_denied(): void
    {
        foreach ([
            new UpdatePackageManifest('1.02.3', '1.2.0', str_repeat('a', 64), 'Notes', true),
            new UpdatePackageManifest('1.2.3', '1.02.0', str_repeat('a', 64), 'Notes', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('A', 64), 'Notes', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('a', 63), 'Notes', true),
            new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('a', 64), "\xFF", true),
        ] as $invalid) {
            $this->assertDenied(fn () => $this->verifier->canonicalPayload($invalid, 'foodex-publisher-2026'));
        }
    }

    private function manifest(): UpdatePackageManifest
    {
        return new UpdatePackageManifest('1.2.3', '1.2.0', str_repeat('a', 64), 'تحديث آمن / Safe update', true);
    }

    private function sign(UpdatePackageManifest $manifest): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            $this->verifier->canonicalPayload($manifest, 'foodex-publisher-2026'),
            $this->secretKey,
        ));
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Unsigned, untrusted or tampered publisher material was accepted.');
    }
}
