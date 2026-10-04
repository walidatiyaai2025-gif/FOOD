<?php

namespace App\Services;

use App\Models\SystemInspectorEvent;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SystemInspectorRecorder
{
    public function recordException(Throwable $exception, Request $request): void
    {
        if ($exception instanceof ValidationException || $exception instanceof AuthenticationException) {
            return;
        }

        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        $this->write([
            'source' => $request->is('api/*')
                ? 'api'
                : ($status === 404 ? 'route' : 'server'),
            'severity' => $status >= 500 ? 'error' : 'warning',
            'status_code' => $status,
            'message' => $this->sanitizeText(
                $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class,
                2000,
            ),
            'exception_class' => $exception::class,
            'context' => [
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(8)
                    ->map(fn (array $frame): array => [
                        'file' => isset($frame['file']) ? basename((string) $frame['file']) : null,
                        'line' => $frame['line'] ?? null,
                        'class' => isset($frame['class']) ? $this->sanitizeText((string) $frame['class'], 240) : null,
                        'function' => isset($frame['function']) ? $this->sanitizeText((string) $frame['function'], 240) : null,
                    ])
                    ->values()
                    ->all(),
            ],
        ], $request);
    }

    /** @param array<string,mixed> $payload */
    public function recordClient(array $payload, Request $request): void
    {
        $source = in_array(($payload['source'] ?? null), ['javascript', 'fetch'], true)
            ? (string) $payload['source']
            : 'javascript';
        $severity = ($payload['severity'] ?? null) === 'warning' ? 'warning' : 'error';

        $context = [];
        foreach (['stack', 'filename', 'line', 'column', 'response_url'] as $key) {
            if (array_key_exists($key, $payload)) {
                $context[$key] = is_string($payload[$key])
                    ? $this->sanitizeText($payload[$key], $key === 'stack' ? 10000 : 2048)
                    : $payload[$key];
            }
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'method' => isset($payload['method']) ? $this->limit((string) $payload['method'], 12) : null,
            'url' => isset($payload['url']) ? $this->safePath((string) $payload['url']) : null,
            'message' => $this->sanitizeText((string) ($payload['message'] ?? 'Client runtime error'), 2000),
            'context' => $context === [] ? null : $context,
        ], $request);
    }

    /** @param array<string,mixed> $payload */
    public function recordMobile(array $payload, Request $request, ?int $authorizedStoreId): void
    {
        $app = ($payload['app'] ?? null) === 'driver' ? 'driver' : 'customer';
        $source = $app === 'driver' ? 'driver_app' : 'customer_app';
        $severity = ($payload['severity'] ?? null) === 'warning' ? 'warning' : 'error';
        $status = isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null;
        $method = isset($payload['method']) ? strtoupper($this->limit((string) $payload['method'], 12)) : null;
        $path = isset($payload['path']) ? $this->safePath((string) $payload['path']) : null;
        $message = $this->sanitizeText((string) ($payload['message'] ?? 'Mobile runtime error'), 2000);
        $correlationId = isset($payload['correlation_id'])
            ? $this->sanitizeText((string) $payload['correlation_id'], 100)
            : null;

        $context = array_filter([
            'category' => isset($payload['category']) ? $this->sanitizeText((string) $payload['category'], 120) : null,
            'app_version' => isset($payload['app_version']) ? $this->sanitizeText((string) $payload['app_version'], 80) : null,
            'app_build' => isset($payload['app_build']) ? $this->sanitizeText((string) $payload['app_build'], 80) : null,
            'platform' => isset($payload['platform']) ? $this->sanitizeText((string) $payload['platform'], 40) : null,
            'os_version' => isset($payload['os_version']) ? $this->sanitizeText((string) $payload['os_version'], 240) : null,
            'current_route' => isset($payload['current_route']) ? $this->safePath((string) $payload['current_route']) : null,
            'channel' => isset($payload['channel']) ? strtolower($this->sanitizeText((string) $payload['channel'], 20)) : null,
            'order_id' => isset($payload['order_id']) && is_numeric($payload['order_id']) ? (int) $payload['order_id'] : null,
            'invoice_id' => isset($payload['invoice_id']) && is_numeric($payload['invoice_id']) ? (int) $payload['invoice_id'] : null,
            'assignment_id' => isset($payload['assignment_id']) && is_numeric($payload['assignment_id']) ? (int) $payload['assignment_id'] : null,
            'retry' => isset($payload['retry']) ? (bool) $payload['retry'] : null,
            'attempt' => isset($payload['attempt']) && is_numeric($payload['attempt']) ? (int) $payload['attempt'] : null,
            'stack' => isset($payload['stack']) ? $this->sanitizeText((string) $payload['stack'], 10000) : null,
            'metadata' => isset($payload['metadata']) ? $this->sanitizeValue($payload['metadata']) : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $fingerprint = hash('sha256', implode('|', [
            (string) $request->user()?->getAuthIdentifier(),
            $source,
            (string) ($context['category'] ?? ''),
            (string) $status,
            (string) $method,
            (string) $path,
            $message,
            (string) $authorizedStoreId,
        ]));

        if (! Cache::add('system-inspector:mobile:'.$fingerprint, true, now()->addMinutes(5))) {
            return;
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => $status,
            'method' => $method,
            'url' => $path,
            'message' => $message,
            'correlation_id' => $correlationId,
            'store_id' => $authorizedStoreId,
            'context' => $context === [] ? null : $context,
        ], $request);
    }

    /** @param array<string,mixed> $data */
    private function write(array $data, Request $request): void
    {
        try {
            if (! Schema::hasTable('system_inspector_events')) {
                return;
            }

            $route = $request->route();
            $routeName = $route instanceof Route ? $route->getName() : null;
            $storeId = array_key_exists('store_id', $data)
                ? $data['store_id']
                : $this->storeId($request);
            $correlationId = array_key_exists('correlation_id', $data)
                ? $data['correlation_id']
                : $request->attributes->get('correlation_id');

            SystemInspectorEvent::query()->create([
                'source' => $data['source'],
                'severity' => $data['severity'],
                'status_code' => $data['status_code'] ?? null,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'store_id' => is_numeric($storeId) ? (int) $storeId : null,
                'method' => $data['method'] ?? $request->method(),
                'route_name' => $routeName,
                'url' => $data['url'] ?? '/'.ltrim($request->path(), '/'),
                'message' => $data['message'],
                'exception_class' => $data['exception_class'] ?? null,
                'correlation_id' => is_string($correlationId) ? $this->sanitizeText($correlationId, 100) : null,
                'context' => $data['context'] ?? null,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $recordingFailure) {
            Log::warning('System Inspector could not persist an event.', [
                'exception' => $recordingFailure::class,
            ]);
        }
    }

    private function storeId(Request $request): ?int
    {
        foreach ([
            $request->input('store_id'),
            $request->query('store_id'),
            $request->input('store'),
            $request->query('store'),
            $request->route('store'),
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }

            if (is_object($candidate) && method_exists($candidate, 'getKey') && is_numeric($candidate->getKey())) {
                return (int) $candidate->getKey();
            }
        }

        return null;
    }

    private function safePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = parse_url($value);
        if (is_array($parts)) {
            $path = (string) ($parts['path'] ?? '/');
            return $this->sanitizeText($path === '' ? '/' : $path, 4096);
        }

        return $this->sanitizeText(strtok($value, '?#') ?: '/', 4096);
    }

    private function sanitizeText(string $value, int $length): string
    {
        $value = trim($value);
        $value = (string) preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value);
        $value = (string) preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[REDACTED_EMAIL]', $value);
        $value = (string) preg_replace('/(?<!\d)(?:\+?965[\s-]?)?[24569]\d{7}(?!\d)/', '[REDACTED_PHONE]', $value);
        $value = (string) preg_replace('/(?<!\d)\d{12}(?!\d)/', '[REDACTED_CIVIL_ID]', $value);
        $value = (string) preg_replace('/(?<!\d)-?\d{1,3}\.\d{4,}\s*[,\/]\s*-?\d{1,3}\.\d{4,}(?!\d)/', '[REDACTED_COORDINATES]', $value);
        $value = (string) preg_replace(
            '/\b(password|passcode|token|access[_-]?token|refresh[_-]?token|fcm[_-]?token|push[_-]?token|guest[_-]?token|authorization|cookie|secret|client[_-]?secret|email|phone(?:[_-]?number)?|civil(?:[_-]?(?:id|number))?|address|latitude|longitude|coordinates|card[_-]?number|cvv)\s*[:=]\s*[^\n;,&]+/i',
            '$1=[REDACTED]',
            $value,
        );

        return $this->limit($value, $length);
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->sensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $itemKey => $itemValue) {
                $safe[(string) $itemKey] = $this->sanitizeValue($itemValue, (string) $itemKey);
            }

            return $safe;
        }

        if (is_string($value)) {
            return $this->sanitizeText($value, 4000);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return $this->sanitizeText((string) $value, 4000);
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach ([
            'password', 'passcode', 'token', 'authorization', 'cookie', 'secret',
            'email', 'phone', 'civilid', 'civilnumber', 'address', 'latitude',
            'longitude', 'coordinates', 'cardnumber', 'cvv', 'requestbody',
            'responsebody', 'filepath', 'storagepath',
        ] as $needle) {
            if ($normalized === $needle || str_ends_with($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
