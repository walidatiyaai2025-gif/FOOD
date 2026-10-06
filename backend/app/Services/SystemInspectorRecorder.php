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

        if ($status < 500 && ! $request->is('admin/*')) {
            return;
        }

        if ($status === 404 && $this->isKnownScannerProbe($request)) {
            return;
        }

        $source = $status === 404
            ? 'route'
            : ($request->is('api/*') ? 'api' : ($request->is('admin/*') ? 'dashboard' : 'server'));

        $this->write([
            'source' => $source,
            'severity' => $status >= 500 ? 'error' : 'warning',
            'status_code' => $status,
            'message' => $this->sanitizeText($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class, 2000),
            'exception_class' => $this->sanitizeText($exception::class, 255),
            'context' => [
                'category' => $status === 503
                    ? 'maintenance'
                    : ($status === 409
                        ? 'domain_rejection'
                        : ($status === 403
                            ? 'authorization_rejection'
                            : ($status === 422 ? 'validation_rejection' : ($status >= 500 ? 'server_failure' : 'http_rejection')))),
                'file' => $this->sanitizeFilePath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(8)
                    ->map(fn (array $frame): array => [
                        'file' => isset($frame['file']) ? $this->sanitizeFilePath((string) $frame['file']) : null,
                        'line' => $frame['line'] ?? null,
                        'class' => isset($frame['class']) ? $this->sanitizeText((string) $frame['class'], 255) : null,
                        'function' => $this->sanitizeText((string) $frame['function'], 255),
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
        foreach (['stack', 'filename', 'line', 'column', 'response_url', 'category'] as $key) {
            if (array_key_exists($key, $payload)) {
                $context[$key] = $this->sanitizeContextValue($payload[$key], $key);
            }
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'method' => isset($payload['method']) ? $this->sanitizeText((string) $payload['method'], 12) : null,
            'url' => isset($payload['url']) ? $this->sanitizeUrl((string) $payload['url']) : null,
            'message' => $this->sanitizeText((string) ($payload['message'] ?? 'Client runtime error'), 2000),
            'context' => $context === [] ? null : $context,
        ], $request);
    }

    /** @param array<string,mixed> $payload */
    public function recordMobile(array $payload, Request $request, ?int $authorizedStoreId = null): void
    {
        $app = match ($payload['app'] ?? null) {
            'driver' => 'driver',
            'van' => 'van',
            default => 'customer',
        };
        $source = $app.'_app';
        $severity = ($payload['severity'] ?? null) === 'warning' ? 'warning' : 'error';

        $context = [];
        foreach ([
            'category', 'app_version', 'app_build', 'platform', 'os_version', 'current_route', 'channel',
            'order_id', 'invoice_id', 'assignment_id', 'route_id', 'manifest_id', 'visit_id', 'collection_id',
            'remittance_id', 'retry', 'attempt', 'stack', 'metadata',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $context[$key] = $this->sanitizeContextValue($payload[$key], $key);
            }
        }

        $this->write([
            'source' => $source,
            'severity' => $severity,
            'status_code' => isset($payload['status']) && is_numeric($payload['status']) ? (int) $payload['status'] : null,
            'method' => isset($payload['method']) ? $this->sanitizeText((string) $payload['method'], 12) : null,
            'url' => isset($payload['path']) ? $this->sanitizeUrl((string) $payload['path']) : null,
            'message' => $this->sanitizeText((string) ($payload['message'] ?? 'Mobile runtime error'), 2000),
            'correlation_id' => isset($payload['correlation_id']) ? $this->sanitizeText((string) $payload['correlation_id'], 100) : null,
            'context' => $context === [] ? null : $context,
        ], $request, $authorizedStoreId, true);
    }

    /** @param array<string,mixed> $data */
    private function write(
        array $data,
        Request $request,
        ?int $storeIdOverride = null,
        bool $storeIdIsAuthoritative = false,
    ): void {
        try {
            if (! Schema::hasTable('system_inspector_events')) {
                return;
            }

            $route = $request->route();
            $routeName = $route instanceof Route ? $route->getName() : null;
            $storeId = $storeIdIsAuthoritative ? $storeIdOverride : $this->storeId($request);
            $userId = $request->user()?->getAuthIdentifier();
            $source = $this->sanitizeText((string) ($data['source'] ?? 'server'), 32);
            $severity = ($data['severity'] ?? null) === 'warning' ? 'warning' : 'error';
            $method = isset($data['method']) ? $this->sanitizeText((string) $data['method'], 12) : $request->method();
            $url = isset($data['url']) ? $this->sanitizeUrl((string) $data['url']) : $this->sanitizeUrl('/'.ltrim($request->path(), '/'));
            $message = $this->sanitizeText((string) ($data['message'] ?? 'Runtime error'), 2000);
            $correlationId = isset($data['correlation_id'])
                ? $this->sanitizeText((string) $data['correlation_id'], 100)
                : $this->sanitizeText((string) ($request->attributes->get('correlation_id') ?? ''), 100);
            $correlationId = $correlationId !== '' ? $correlationId : null;
            $occurredAt = now();

            $duplicate = SystemInspectorEvent::query()
                ->where('source', $source)
                ->where('severity', $severity)
                ->where('message', $message)
                ->where('method', $method)
                ->where('url', $url)
                ->where('status_code', $data['status_code'] ?? null)
                ->where('user_id', $userId)
                ->where('store_id', $storeId)
                ->where('occurred_at', '>=', $occurredAt->copy()->subMinute())
                ->exists();

            if ($duplicate) {
                return;
            }

            SystemInspectorEvent::query()->create([
                'source' => $source,
                'severity' => $severity,
                'status_code' => $data['status_code'] ?? null,
                'user_id' => $userId,
                'store_id' => $storeId,
                'method' => $method,
                'route_name' => $routeName,
                'url' => $url,
                'message' => $message,
                'exception_class' => isset($data['exception_class']) ? $this->sanitizeText((string) $data['exception_class'], 255) : null,
                'correlation_id' => $correlationId,
                'context' => isset($data['context']) ? $this->sanitizeContextValue($data['context']) : null,
                'occurred_at' => $occurredAt,
            ]);
        } catch (Throwable $recordingFailure) {
            Log::warning('System Inspector could not persist an event.', [
                'exception' => $recordingFailure::class,
            ]);
        }
    }

    private function isKnownScannerProbe(Request $request): bool
    {
        $path = '/'.ltrim(strtolower($request->path()), '/');

        return preg_match(
            '#/(?:wp-admin|wp-content|wp-includes|wordpress(?:/|$)|blog/(?:wp-|xmlrpc\.php)|xmlrpc\.php(?:$|/)|wlwmanifest\.xml$)#',
            $path,
        ) === 1;
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

    private function sanitizeUrl(string $value): string
    {
        $value = $this->sanitizeText($value, 4096);
        $queryPosition = strpos($value, '?');
        if ($queryPosition !== false) {
            $value = substr($value, 0, $queryPosition);
        }
        $fragmentPosition = strpos($value, '#');
        if ($fragmentPosition !== false) {
            $value = substr($value, 0, $fragmentPosition);
        }

        return $this->limit($value, 4096);
    }

    private function sanitizeFilePath(string $value): string
    {
        $normalized = str_replace('\\', '/', trim($value));
        if ($normalized === '') {
            return '';
        }

        return $this->sanitizeText(basename($normalized), 255);
    }

    private function sanitizeContextValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/password|passcode|token|authorization|cookie|secret|email|phone|civil|address|latitude|longitude|coordinates|\\blat\\b|\\blng\\b|request_body|response_body|file_path|storage_path|payload/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 50, true) as $childKey => $childValue) {
                $result[(string) $childKey] = $this->sanitizeContextValue($childValue, (string) $childKey);
            }

            return $result;
        }

        if (is_string($value)) {
            if (in_array($key, ['current_route', 'route', 'response_url'], true)) {
                return $this->sanitizeUrl($value);
            }

            return $this->sanitizeText($value, $key === 'stack' ? 10000 : 2048);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return $this->sanitizeText((string) $value, 2048);
    }

    private function sanitizeText(string $value, int $length): string
    {
        $patterns = [
            '/Bearer\\s+[A-Za-z0-9._~+\\/=:-]+/i' => 'Bearer [REDACTED]',
            '/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i' => '[REDACTED_EMAIL]',
            '/(?<!\\d)(?:\\+?965[\\s-]?)?[24569]\\d{7}(?!\\d)/' => '[REDACTED_PHONE]',
            '/(?<!\\d)\\d{12}(?!\\d)/' => '[REDACTED_CIVIL_ID]',
            '/(?<!\\d)-?\\d{1,3}\\.\\d{4,}\\s*[,\\/]\\s*-?\\d{1,3}\\.\\d{4,}(?!\\d)/' => '[REDACTED_COORDINATES]',
            '/\\b(password|passcode|token|access[_-]?token|refresh[_-]?token|authorization|cookie|secret|client[_-]?secret|email|phone(?:[_-]?number)?|civil(?:[_-]?(?:id|number))?|address|latitude|longitude|coordinates|card[_-]?number|cvv)\\s*[:=]\\s*[^\\n;,&]+/i' => '$1=[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $this->limit($value, $length);
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
