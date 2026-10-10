<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerConditionalGet
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (
            ! $request->isMethod('GET')
            || $request->header('X-FOODEX-Low-Data') !== '1'
            || strtolower((string) $request->header('X-FOODEX-Client')) !== 'customer'
            || $response->getStatusCode() !== 200
            || ! str_contains(
                strtolower((string) $response->headers->get('Content-Type', '')),
                'json',
            )
        ) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content)) {
            return $response;
        }

        $payloadBytes = strlen($content);
        $etag = '"'.hash('sha256', $content).'"';
        $isPrivate = $request->bearerToken() !== null
            || trim((string) $request->header('X-Guest-Token', '')) !== ''
            || $this->isSensitivePath($request->path());
        $cacheControl = $isPrivate
            ? 'private, max-age=0, must-revalidate'
            : 'public, max-age=60, stale-while-revalidate=300, stale-if-error=86400';

        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', $cacheControl);
        $response->headers->set('X-FOODEX-Conditional', '1');
        $response->headers->set('X-FOODEX-Payload-Bytes', (string) $payloadBytes);
        $response->headers->set(
            'Vary',
            $this->mergeVary(
                (string) $response->headers->get('Vary', ''),
                [
                    'Accept',
                    'Accept-Encoding',
                    'Authorization',
                    'X-Guest-Token',
                    'X-FOODEX-Store-ID',
                    'X-FOODEX-Customer-Domain',
                    'X-FOODEX-Data-Mode',
                ],
            ),
        );

        if (! $this->etagMatches($request->header('If-None-Match'), $etag)) {
            return $response;
        }

        $notModified = response('', 304);
        foreach (
            [
                'ETag',
                'Cache-Control',
                'Vary',
                'Last-Modified',
                'X-FOODEX-Conditional',
                'X-FOODEX-Payload-Bytes',
            ] as $header
        ) {
            $value = $response->headers->get($header);
            if ($value !== null) {
                $notModified->headers->set($header, $value);
            }
        }

        return $notModified;
    }

    private function isSensitivePath(string $path): bool
    {
        $path = ltrim($path, '/');

        foreach (
            [
                'api/v1/cart',
                'api/v1/orders',
                'api/v1/b2b/orders',
                'api/v1/profile',
                'api/v1/notifications',
                'api/v1/favorites',
                'api/v1/addresses',
                'api/v1/account-deletion',
                'api/v1/checkout',
            ] as $prefix
        ) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function etagMatches(?string $candidate, string $etag): bool
    {
        if ($candidate === null || trim($candidate) === '') {
            return false;
        }

        foreach (explode(',', $candidate) as $value) {
            $normalized = trim($value);
            if ($normalized === '*' || $normalized === $etag) {
                return true;
            }
            if (str_starts_with($normalized, 'W/') && substr($normalized, 2) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $required
     */
    private function mergeVary(string $existing, array $required): string
    {
        $values = collect(explode(',', $existing))
            ->map(static fn (string $value): string => trim($value))
            ->filter()
            ->merge($required)
            ->unique(static fn (string $value): string => strtolower($value))
            ->values()
            ->all();

        return implode(', ', $values);
    }
}
