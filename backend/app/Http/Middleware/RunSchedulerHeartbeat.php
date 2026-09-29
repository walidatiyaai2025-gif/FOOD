<?php

namespace App\Http\Middleware;

use App\Domain\Installer\InstallState;
use App\Services\SchedulerRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RunSchedulerHeartbeat
{
    public function __construct(
        private readonly SchedulerRuntime $scheduler,
        private readonly InstallState $installState,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->is('install') || $request->is('install/*') || ! $this->installState->isInstalled()) {
            return;
        }

        $this->scheduler->tickIfDue();
    }
}
