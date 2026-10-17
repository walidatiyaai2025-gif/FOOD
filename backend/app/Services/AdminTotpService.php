<?php

namespace App\Services;

use App\Models\AdminMfaCredential;
use Illuminate\Support\Str;
use RuntimeException;

final class AdminTotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, max(4, (int) config('security.mfa.recovery_codes', 8))))
            ->map(fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /** @param list<string> $codes
     * @return list<string>
     */
    public function recoveryHashes(array $codes): array
    {
        return array_map(fn (string $code): string => $this->recoveryHash($code), $codes);
    }

    public function provisioningUri(string $email, string $secret): string
    {
        $issuer = (string) config('security.mfa.issuer', 'FOODEX');
        $label = rawurlencode($issuer.':'.$email);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&digits=%d&period=%d',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer),
            (int) config('security.mfa.digits', 6),
            (int) config('security.mfa.period', 30),
        );
    }

    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $digits = (int) config('security.mfa.digits', 6);
        if (strlen($code) !== $digits) {
            return false;
        }

        $period = max(1, (int) config('security.mfa.period', 30));
        $window = max(0, (int) config('security.mfa.window', 1));
        $counter = intdiv($timestamp ?? time(), $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->at($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function consumeRecoveryCode(AdminMfaCredential $credential, string $code): bool
    {
        $needle = $this->recoveryHash($code);
        $hashes = $credential->recovery_codes;
        $index = array_search($needle, $hashes, true);

        if ($index === false) {
            return false;
        }

        array_splice($hashes, $index, 1);
        $credential->forceFill([
            'recovery_codes' => $hashes,
            'last_verified_at' => now(),
        ])->save();

        return true;
    }

    private function at(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter & 0xFFFFFFFF;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        $modulo = 10 ** (int) config('security.mfa.digits', 6);

        return str_pad((string) ($value % $modulo), (int) config('security.mfa.digits', 6), '0', STR_PAD_LEFT);
    }

    private function recoveryHash(string $code): string
    {
        return hash_hmac(
            'sha256',
            strtoupper(preg_replace('/\s+/', '', trim($code)) ?? ''),
            (string) config('app.key'),
        );
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        if ($value === '') {
            throw new RuntimeException('Invalid MFA secret.');
        }

        $bits = '';
        foreach (str_split($value) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                throw new RuntimeException('Invalid MFA secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
