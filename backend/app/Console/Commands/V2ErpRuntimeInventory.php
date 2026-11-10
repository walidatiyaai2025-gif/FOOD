<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

final class V2ErpRuntimeInventory extends Command
{
    protected $signature = 'foodex:v2-erp-inventory';

    protected $description = 'Emit a read-only, redacted ERP runtime and route inventory for isolated V2 acceptance';

    private const ROUTE_KEYWORDS = [
        'erp', 'enterprise', 'b2b', 'wholesale', 'procurement',
        'supplier', 'treasury', 'ledger', 'finance',
    ];

    public function handle(): int
    {
        $manifestPath = base_path('composer.json');
        $manifest = is_file($manifestPath)
            ? json_decode((string) file_get_contents($manifestPath), true)
            : null;

        $required = is_array($manifest) && is_array($manifest['require'] ?? null)
            ? $manifest['require']
            : [];
        $dependencies = array_intersect_key(
            $required,
            array_flip(['php', 'laravel/framework', 'laravel/sanctum', 'tecnickcom/tcpdf']),
        );
        ksort($dependencies);

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! $route instanceof RoutingRoute || ! $this->isRelevantRoute($route)) {
                continue;
            }

            $signals = $this->middlewareSignals($route);
            $routes[] = [
                'name' => $route->getName(),
                'uri' => $route->uri(),
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'channel_hint' => $this->channelHint($route),
                'middleware_signals' => $signals,
                'permission_codes_declared' => array_values(array_map(
                    static fn (string $value): string => substr($value, 4),
                    array_filter($signals, static fn (string $value): bool => str_starts_with($value, 'can:')),
                )),
                'server_authorization_verified' => false,
                'tenant_scope_verified' => false,
                'module_entitlement_verified' => false,
                'operational_readiness_verified' => false,
            ];
        }

        usort($routes, static fn (array $left, array $right): int => strcmp(
            ($left['uri'] ?? '').'|'.($left['name'] ?? ''),
            ($right['uri'] ?? '').'|'.($right['name'] ?? ''),
        ));

        $versionFile = base_path('../VERSION');
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : null;

        $report = [
            'schema_version' => 1,
            'issue' => 2842,
            'source_scope' => 'V2 P108 / runtime-local observation only',
            'runtime_observed' => true,
            'production_deployment_verified' => false,
            'release_version' => $version !== '' ? $version : null,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'composer_manifest_sha256' => is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'declared_production_dependencies' => $dependencies,
            'routes_total' => count(Route::getRoutes()),
            'erp_wholesale_related_routes' => $routes,
            'route_inventory_sha256' => hash('sha256', (string) json_encode($routes, JSON_UNESCAPED_SLASHES)),
            'acceptance' => [
                'environment_identity' => 'not_attested',
                'effective_role_permissions' => 'not_tested',
                'tenant_and_store_isolation' => 'not_tested',
                'module_entitlements' => 'not_tested',
                'erp_end_to_end_readiness' => 'not_tested',
                'customer_driver_van_api_parity' => 'no_contract_change',
            ],
            'privacy' => 'No database reads, user records, environment variables, credentials, or request parameters.',
        ];

        $this->line((string) json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    private function isRelevantRoute(RoutingRoute $route): bool
    {
        $descriptor = strtolower($route->uri().' '.($route->getName() ?? '').' '.$route->getActionName());

        foreach (self::ROUTE_KEYWORDS as $keyword) {
            if (str_contains($descriptor, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function channelHint(RoutingRoute $route): ?string
    {
        $descriptor = strtolower($route->uri().' '.($route->getName() ?? ''));

        if (str_contains($descriptor, 'b2b') || str_contains($descriptor, 'wholesale')) {
            return 'b2b_hint_only';
        }

        if (str_contains($descriptor, 'b2c') || str_contains($descriptor, 'retail')) {
            return 'b2c_hint_only';
        }

        return null;
    }

    /**
     * Only publish known middleware names and permission tokens, never arbitrary
     * middleware parameters (which might contain deployment-specific data).
     *
     * @return list<string>
     */
    private function middlewareSignals(RoutingRoute $route): array
    {
        $signals = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $name = explode(':', $middleware, 2)[0];
            if (! in_array($name, ['auth', 'can', 'role', 'tenant.context', 'management.dashboard', 'verified'], true)) {
                continue;
            }

            if ($name === 'can' && preg_match('/^can:[a-zA-Z0-9_.:-]+$/D', $middleware)) {
                $signals[] = $middleware;
            } elseif ($name !== 'can') {
                $signals[] = $name;
            }
        }

        $signals = array_values(array_unique($signals));
        sort($signals);

        return $signals;
    }
}
