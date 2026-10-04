<?php

namespace App\Services;

use App\Models\SystemInspectorEvent;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
            'source' => $status === 404 ? 'route' : 'server',
            'severity' => $status >= 500 ? 'error' : 'warning',
            'status_code' => $status,
            'message' => $this->limit($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, 2000),
            'exception_class' => $exception::class,
            'context' => [
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(8)
                    ->map(static fn (array $frame): array => [
                        'file' => isset($frame['file']) ? basename((string) $frame['file']) : null,
                        'line' => $frame['line'] ?? null,
                        'class' => $frame['class'] ?? null,
                        'function' => $frame['function'] ?? null,
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
                    ? $this->limit($payload[$key], $key === 'stack' ? 10000 : 2048)
                    : $payload[$key];
            }
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'method' => isset($payload['method']) ? $this->limit((string) $payload['method'], 12) : null,
            'url' => isset($payload['url']) ? $this->limit((string) $payload['url'], 4096) : null,
            'message' => $this->limit((string) ($payload['message'] ?? 'Client runtime error'), 2000),
            'context' => $context === [] ? null : $context,
        ], $request);
    }

    /** @param array<string,mixed> $payload */
    public function recordMobile(array $payload, Request $request): void
    {
        $source = ($payload['app'] ?? null) === 'driver' ? 'driver_app' : 'customer_app';
        $severity = ($payload['severity'] ?? null) === 'warning' ? 'warning' : 'error';

        $context = [];
        foreach ([
            'source',
            'category',
            'app_version',
            'app_build',
            'platform',
            'os_version',
            'route',
            'channel',
            'order_id',
            'invoice_id',
            'assignment_id',
            'retry',
            'elapsed_ms',
            'stack',
            'error_type',
        ] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $context[$key] = $payload[$key];
            }
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'method' => isset($payload['method']) ? $this->limit((string) $payload['method'], 12) : null,
            'url' => isset($payload['path']) ? $this->limit((string) $payload['path'], 4096) : null,
            'message' => $this->limit((string) ($payload['message'] ?? 'Mobile runtime failure'), 2000),
            'correlation_id' => isset($payload['correlation_id'])
                ? $this->limit((string) $payload['correlation_id'], 160)
                : null,
            'context' => $context === [] ? null : $context,
            'dedupe_seconds' => 60,
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
            $storeId = $this->storeId($request);
            $userId = $request->user()?->getAuthIdentifier();
            $source = $this->limit((string) ($data['source'] ?? 'server'), 32);
            $severity = ($data['severity'] ?? null) === 'warning' ? 'warning' : 'error';
            $statusCode = isset($data['status_code']) && is_numeric($data['status_code'])
                ? (int) $data['status_code']
                : null;
            $url = $this->sanitizeUrl((string) ($data['url'] ?? '/'.ltrim($request->path(), '/')));
            $message = $this->limit($this->sanitizeString((string) ($data['message'] ?? 'Runtime failure')), 2000);
            $context = isset($data['context']) && is_array($data['context'])
                ? $this->sanitizeValue($data['context'])
                : null;
            $correlationId = $data['correlation_id'] ?? $request->attributes->get('correlation_id');
            $correlationId = is_scalar($correlationId)
                ? $this->limit($this->sanitizeString((string) $correlationId), 160)
                : null;

            $dedupeSeconds = max(0, min(300, (int) ($data['dedupe_seconds'] ?? 0)));
            if ($dedupeSeconds > 0) {
                $duplicate = SystemInspectorEvent::query()
                    ->where('occurred_at', '>=', now()->subSeconds($dedupeSeconds))
                    ->where('source', $source)
                    ->where('severity', $severity)
                    ->where('user_id', $userId)
                    ->where('message', $message)
                    ->where('url', $url)
                    ->when(
                        $statusCode === null,
                        fn ($query) => $query->whereNull('status_code'),
                        fn ($query) => $query->where('status_code', $statusCode),
                    )
                    ->exists();

                if ($duplicate) {
                    return;
                }
            }

            SystemInspectorEvent::query()->create([
                'source' => $source,
                'severity' => $severity,
                'status_code' => $statusCode,
                'user_id' => $userId,
                'store_id' => $storeId,
                'method' => isset($data['method']) ? $this->limit($this->sanitizeString((string) $data['method']), 12) : $request->method(),
                'route_name' => $routeName,
                'url' => $url,
                'message' => $message,
                'exception_class' => isset($data['exception_class'])
                    ? $this->limit($this->sanitizeString((string) $data['exception_class']), 255)
                    : null,
                'correlation_id' => $correlationId,
                'context' => $context,
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

    private function sanitizeUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = explode('#', $value, 2)[0];
        [$base, $query] = array_pad(explode('?', $value, 2), 2, null);
        $base = $this->sanitizeString($base);

        if ($query === null || $query === '') {
            return $this->limit($base, 4096);
        }

        parse_str($query, $parameters);
        $safe = [];
        foreach ($parameters as $key => $parameter) {
            $safe[(string) $key] = $this->sanitizeValue($parameter, (string) $key);
        }

        $encoded = http_build_query($safe, '', '&', PHP_QUERY_RFC3986);

        return $this->limit($base.($encoded === '' ? '' : '?'.$encoded), 4096);
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $safe = [];
            foreach ($value as $childKey => $childValue) {
                $safe[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $safe;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return $this->sanitizeString((string) $value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach ([
            'password',
            'passcode',
            'authorization',
            'cookie',
            'token',
            'secret',
            'credential',
            'email',
            'phone',
            'civil',
            'address',
            'latitude',
            'longitude',
            'coordinates',
            'cardnumber',
            'cvv',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return in_array($normalized, ['lat', 'lng'], true);
    }

    private function sanitizeString(string $value): string
    {
        $safe = $value;
        $patterns = [
            '/(?<!\d)-?\d{1,3}\.\d{4,}\s*[,\/]\s*-?\d{1,3}\.\d{4,}(?!\d)/' => '[REDACTED_COORDINATES]',
            '/Bearer\s+[^\s,;]+/i' => 'Bearer [REDACTED]',
            '/\b(password|passcode|token|access[_-]?token|refresh[_-]?token|authorization|cookie|secret|client[_-]?secret|email|phone(?:[_-]?number)?|civil(?:[_-]?(?:id|number))?|address|latitude|longitude|coordinates|card[_-]?number|cvv)\s*[:=]\s*[^\s,;&]+/i' => '$1=[REDACTED]',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[REDACTED_EMAIL]',
            '/(?<!\d)(?:\+?965[\s-]?)?[24569]\d{7}(?!\d)/' => '[REDACTED_PHONE]',
            '/(?<!\d)\d{12}(?!\d)/' => '[REDACTED_CIVIL_ID]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $safe = preg_replace($pattern, $replacement, $safe) ?? $safe;
        }

        return $safe;
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
