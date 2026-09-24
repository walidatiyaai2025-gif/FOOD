<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token',
        'access_token', 'refresh_token', 'authorization', 'cookie',
        'secret', 'client_secret', 'api_key', 'otp', 'otp_hash',
    ];

    public function record(
        string $event,
        ?Authenticatable $actor = null,
        mixed $target = null,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'user_id' => $actor?->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => is_object($target) ? $target::class : null,
            'auditable_id' => is_object($target) && method_exists($target, 'getKey') ? $target->getKey() : null,
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = [];
        foreach ($values as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $clean[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $clean[$key] = $this->redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_ends_with($normalized, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }
}
