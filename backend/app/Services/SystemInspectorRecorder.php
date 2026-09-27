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
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => collect($exception->getTrace())
                    ->take(8)
                    ->map(static fn (array $frame): array => [
                        'file' => $frame['file'] ?? null,
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

            SystemInspectorEvent::query()->create([
                'source' => $data['source'],
                'severity' => $data['severity'],
                'status_code' => $data['status_code'] ?? null,
                'user_id' => $request->user()?->getAuthIdentifier(),
                'store_id' => $storeId,
                'method' => $data['method'] ?? $request->method(),
                'route_name' => $routeName,
                'url' => $data['url'] ?? '/'.ltrim($request->path(), '/'),
                'message' => $data['message'],
                'exception_class' => $data['exception_class'] ?? null,
                'correlation_id' => $request->attributes->get('correlation_id'),
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

    private function limit(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
