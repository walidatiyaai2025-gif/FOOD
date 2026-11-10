<?php

namespace App\Http\Middleware;

use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * An ERP menu link or enabled lifecycle state never authorizes operational use.
 * Controllers retain their independent role, store and maker-checker guards.
 */
final class EnsureErpOperationalReadiness
{
    private const ROUTE_MODULES = [
        'admin.erp.procurement.' => 'procurement',
        'admin.erp.wms.' => 'inventory_wms',
        'admin.erp.finance.' => 'finance',
        'admin.erp.treasury.' => 'treasury',
        'admin.erp.bi.' => 'enterprise_bi',
    ];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleOperationalReadiness $readiness,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        if (! is_string($routeName)) {
            return $next($request);
        }

        $moduleCode = $this->moduleForRoute($routeName);
        if ($moduleCode === null) {
            return $next($request);
        }

        $ready = false;

        try {
            $ready = $this->readiness->status(
                $moduleCode,
                $this->modules->manifest($moduleCode)->version,
                $this->modules->state($moduleCode),
            ) === 'ready';
        } catch (Throwable) {
            // Invalid registration and failed probes must never grant access.
            $ready = false;
        }

        abort_unless($ready, 403);

        return $next($request);
    }

    private function moduleForRoute(string $routeName): ?string
    {
        if ($routeName === 'admin.erp.bi') {
            return 'enterprise_bi';
        }

        foreach (self::ROUTE_MODULES as $prefix => $moduleCode) {
            if (str_starts_with($routeName, $prefix)) {
                return $moduleCode;
            }
        }

        return null;
    }
}
