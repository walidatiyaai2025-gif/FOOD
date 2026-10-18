<?php

namespace App\Domain\Assistant\Support;

use App\Domain\Assistant\Data\AiPrincipalContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AssistantContextToken
{
    /**
     * @return array{token:string,expires_at:string,context:array<string,mixed>}
     */
    public function issue(AiPrincipalContext $principal): array
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes(max(1, (int) config('assistant.context_token_minutes', 15)));
        $context = $this->publicContext($principal);
        $claims = [
            'v' => 1,
            'sub' => $principal->actorUserId,
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'locale' => $principal->locale,
            'role_codes' => $principal->roleCodes,
            'business_scope' => $principal->businessScope,
            'page_context' => $principal->pageContext,
        ];

        $payload = $this->encode($claims);
        $signature = $this->sign($payload);

        return [
            'token' => $payload.'.'.$signature,
            'expires_at' => $expiresAt->toIso8601String(),
            'context' => $context,
        ];
    }

    /**
     * Verify only authenticity, ownership and age here. The returned page context MUST be
     * re-resolved through AssistantPageContext and AiPrincipalContextResolver on every turn.
     *
     * @return array<string,mixed>
     */
    public function pageContext(User $user, string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $this->invalid();
        }

        [$payload, $signature] = $parts;
        if (! hash_equals($this->sign($payload), $signature)) {
            $this->invalid();
        }

        try {
            $claims = $this->decode($payload);
        } catch (JsonException) {
            $this->invalid();
        }

        $subject = filter_var($claims['sub'] ?? null, FILTER_VALIDATE_INT);
        $expires = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);
        $version = filter_var($claims['v'] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($subject)
            || $subject !== (int) $user->getKey()
            || ! is_int($expires)
            || $expires < CarbonImmutable::now()->getTimestamp()
            || $version !== 1
            || ! is_array($claims['page_context'] ?? null)
        ) {
            $this->invalid();
        }

        return $claims['page_context'];
    }

    /** @return array<string,mixed> */
    private function publicContext(AiPrincipalContext $principal): array
    {
        $page = $principal->pageContext;

        return [
            'route' => is_string($page['route'] ?? null) ? $page['route'] : ($page['path'] ?? '/admin'),
            'domain' => is_string($page['domain'] ?? null) ? $page['domain'] : 'management',
            'channel' => $principal->channel,
            'store_id' => $principal->activeStoreId,
            'authorized_entities' => is_array($page['authorized_entities'] ?? null) ? $page['authorized_entities'] : [],
            'role_codes' => $principal->roleCodes,
            'locale' => $principal->locale,
            'business_scope' => $principal->businessScope,
        ];
    }

    /** @param array<string,mixed> $claims */
    private function encode(array $claims): string
    {
        return $this->base64UrlEncode(json_encode(
            $claims,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @return array<string,mixed> */
    private function decode(string $payload): array
    {
        $json = $this->base64UrlDecode($payload);
        $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($value)) {
            $this->invalid();
        }

        return $value;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey(), true));
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            $this->invalid();
        }

        return $decoded;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'context_token' => ['Assistant page context is invalid or expired. Refresh the page context and try again.'],
        ]);
    }
}
