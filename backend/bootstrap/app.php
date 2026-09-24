<?php

use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureManagementDashboardAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [CorrelationId::class]);

        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'management.dashboard' => EnsureManagementDashboardAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $exception instanceof \Illuminate\Validation\ValidationException || $exception instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            $correlationId = $request->attributes->get('correlation_id');
            \Illuminate\Support\Facades\Log::error('Unhandled API exception', [
                'correlation_id' => $correlationId,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'An unexpected server error occurred.',
                'correlation_id' => $correlationId,
            ], 500);
        });
    })->create();
