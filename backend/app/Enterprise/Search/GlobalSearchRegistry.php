<?php

namespace App\Enterprise\Search;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\User;
use Closure;
use LogicException;

final class GlobalSearchRegistry
{
    /** @var array<string, array{factory:Closure,module:?string,scope:?Closure,capability:?string}> */
    private array $providers = [];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleEntitlementResolver $entitlements,
    ) {}

    public function registerCore(string $key, Closure $factory): self
    {
        $this->providers[$key] = [
            'factory' => $factory,
            'module' => null,
            'scope' => null,
            'capability' => null,
        ];

        return $this;
    }

    public function registerModule(
        string $key,
        string $moduleCode,
        Closure $scope,
        Closure $factory,
        ?string $capability = null,
    ): self {
        $this->providers[$key] = [
            'factory' => $factory,
            'module' => $moduleCode,
            'scope' => $scope,
            'capability' => $capability,
        ];

        return $this;
    }

    /**
     * Optional-module factories stay unresolved until the module is both enabled
     * and entitled for the actor's authoritative scope.
     *
     * @return list<array{type:string,type_label:string,title:string,subtitle:string,url:string}>
     */
    public function search(User $actor, string $query, int $limitPerProvider = 5): array
    {
        $limitPerProvider = max(1, min(8, $limitPerProvider));
        $results = [];

        foreach ($this->providers as $definition) {
            $moduleCode = $definition['module'];

            if (is_string($moduleCode)) {
                if (! $this->modules->isEnabled($moduleCode)) {
                    continue;
                }

                $scopeResolver = $definition['scope'];
                $scope = $scopeResolver instanceof Closure ? $scopeResolver($actor) : null;
                if (! $scope instanceof EntitlementScope) {
                    continue;
                }

                if (! $this->entitlements->allows($moduleCode, $scope, $definition['capability'])) {
                    continue;
                }
            }

            $provider = ($definition['factory'])();
            if (! $provider instanceof GlobalSearchProvider) {
                throw new LogicException('Global search provider factory returned an invalid provider.');
            }

            foreach ($provider->search($actor, $query, $limitPerProvider) as $result) {
                $results[] = $result;
            }
        }

        return array_slice($results, 0, 24);
    }
}
