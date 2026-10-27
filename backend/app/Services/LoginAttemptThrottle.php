<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class LoginAttemptThrottle
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function ensureAllowed(Request $request, string $identifier): void
    {
        $key = $this->key($request, $identifier);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $retryAfter = max(1, RateLimiter::availableIn($key));

        throw new TooManyRequestsHttpException($retryAfter, 'Too Many Requests.');
    }

    public function hit(Request $request, string $identifier): void
    {
        RateLimiter::hit($this->key($request, $identifier), self::DECAY_SECONDS);
    }

    public function clear(Request $request, string $identifier): void
    {
        RateLimiter::clear($this->key($request, $identifier));
    }

    private function key(Request $request, string $identifier): string
    {
        $normalized = Str::lower(trim($identifier));

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            $normalized = preg_replace('/[\\s().-]+/', '', $normalized) ?? $normalized;

            if (str_starts_with($normalized, '00')) {
                $normalized = '+'.substr($normalized, 2);
            }
        }

        return 'auth-login-failure:'.hash('sha256', $normalized.'|'.$request->ip());
    }
}
