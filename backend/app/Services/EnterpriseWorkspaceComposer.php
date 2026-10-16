<?php

namespace App\Services;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class EnterpriseWorkspaceComposer
{
    public function __construct(
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly TenantContextResolver $tenants,
    ) {}

    /** @return list<string> */
    public function availableWorkspaceKeys(User $actor): array
    {
        $available = [];

        foreach ($this->definitions() as $key => $definition) {
            if ($this->workspaceAccessScope($actor, $definition) !== null) {
                $available[] = $key;
            }
        }

        return $available;
    }

    /**
     * @return array{
     *   key:string,
     *   cards:list<array{key:string,label:string,description:string,url:string}>,
     *   available:list<string>
     * }|null
     */
    public function compose(User $actor, string $workspace): ?array
    {
        $definitions = $this->definitions();
        $definition = $definitions[$workspace] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Unknown enterprise workspace [{$workspace}].");
        }

        $scope = $this->workspaceAccessScope($actor, $definition);
        if ($scope === null) {
            return null;
        }

        $cards = [];
        foreach ((array) ($definition['cards'] ?? []) as $card) {
            if (is_array($card) === false) {
                continue;
            }

            $permissionScope = $this->cardPermissionScope($actor, $card);
            if ($permissionScope === null) {
                continue;
            }

            $module = $card['module'] ?? null;
            if (is_string($module) && $module !== '') {
                $capability = is_string($card['capability'] ?? null) ? $card['capability'] : null;
                if ($this->entitlements->allows($module, $permissionScope, $capability) === false) {
                    continue;
                }
            }

            $routeName = $card['route'] ?? null;
            if (is_string($routeName) === false || Route::has($routeName) === false) {
                continue;
            }

            $cards[] = [
                'key' => (string) ($card['key'] ?? $routeName),
                'label' => (string) ($card['label'] ?? ''),
                'description' => (string) ($card['description'] ?? ''),
                'url' => route($routeName, is_array($card['params'] ?? null) ? $card['params'] : []),
            ];
        }

        return [
            'key' => $workspace,
            'cards' => $cards,
            'available' => $this->availableWorkspaceKeys($actor),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $definitions = [];
        foreach ((array) config('enterprise_workspaces.workspaces', []) as $key => $definition) {
            if (is_string($key) && is_array($definition)) {
                $definitions[$key] = $definition;
            }
        }

        return $definitions;
    }

    /** @param array<string, mixed> $definition */
    private function workspaceAccessScope(User $actor, array $definition): ?EntitlementScope
    {
        $permissions = array_values(array_filter(
            (array) ($definition['access_any'] ?? []),
            'is_string',
        ));

        foreach ($permissions as $permission) {
            $scope = $this->permissionScope($actor, $permission);
            if ($scope !== null) {
                return $scope;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $card */
    private function cardPermissionScope(User $actor, array $card): ?EntitlementScope
    {
        $permission = $card['permission'] ?? null;
        if (is_string($permission) && $permission !== '') {
            return $this->permissionScope($actor, $permission);
        }

        $permissions = array_values(array_filter(
            (array) ($card['permissions_any'] ?? []),
            'is_string',
        ));

        foreach ($permissions as $candidate) {
            $scope = $this->permissionScope($actor, $candidate);
            if ($scope !== null) {
                return $scope;
            }
        }

        return $permissions === [] ? EntitlementScope::platform() : null;
    }

    private function permissionScope(User $actor, string $permission): ?EntitlementScope
    {
        if ($actor->hasPermission($permission)) {
            return EntitlementScope::platform();
        }

        foreach ($this->tenants->retailStoreIds($actor) as $storeId) {
            if ($actor->hasPermission($permission, $storeId)) {
                return new EntitlementScope(EntitlementScope::STORE, $storeId);
            }
        }

        return null;
    }
}
