<?php

namespace App\Services;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class BuiltInJobRoleCatalog
{
    public function __construct(
        private readonly ModuleManifestCatalog $modules,
        private readonly ModuleRegistry $registry,
        private readonly ModuleEntitlementResolver $entitlements,
    ) {}

    /** @return Collection<string,array<string,mixed>> */
    public function definitions(): Collection
    {
        return collect((array) config('job_roles.roles', []))
            ->map(function (array $definition, string $code): array {
                $normalized = [
                    'code' => $code,
                    'domain' => (string) ($definition['domain'] ?? 'administration'),
                    'scope' => (string) ($definition['scope'] ?? 'global'),
                    'business_scope' => (string) ($definition['business_scope'] ?? ($definition['scope'] ?? 'global')),
                    'level' => (string) ($definition['level'] ?? 'specialist'),
                    'required_modules' => array_values(array_unique(array_map('strval', (array) ($definition['required_modules'] ?? [])))),
                    'optional_modules' => array_values(array_unique(array_map('strval', (array) ($definition['optional_modules'] ?? [])))),
                    'permissions' => $this->defaultPermissions($code, $definition),
                    'is_system' => true,
                    'sort' => (int) ($definition['sort'] ?? 9999),
                ];

                $this->assertDefinition($normalized);

                return $normalized;
            })
            ->sortBy([['sort', 'asc'], ['code', 'asc']]);
    }

    /** @return array<string,mixed>|null */
    public function definition(string $code): ?array
    {
        return $this->definitions()->get($code);
    }

    public function isBuiltIn(string $code): bool
    {
        return $this->definition($code) !== null;
    }

    /**
     * Availability is additive to RBAC, never a replacement for it.
     * Global roles are gated by module lifecycle. Store roles are also gated by
     * the existing enterprise store entitlement. Any failure is fail-closed.
     */
    public function isAvailable(string $code, ?int $storeId = null): bool
    {
        $definition = $this->definition($code);
        if ($definition === null) {
            return true; // advanced custom role; existing RbacManager still authorizes it.
        }

        if ($definition['scope'] === 'store' && $storeId === null) {
            return false;
        }
        if ($definition['scope'] === 'global' && $storeId !== null) {
            return false;
        }

        try {
            foreach ($definition['required_modules'] as $moduleCode) {
                if (! $this->registry->isEnabled($moduleCode)) {
                    return false;
                }

                if ($storeId !== null && ! $this->entitlements->allows(
                    $moduleCode,
                    new EntitlementScope(EntitlementScope::STORE, $storeId),
                )) {
                    return false;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Existing unavailable assignments may be retained for audit/re-enable.
     * A crafted request cannot add an unavailable built-in role.
     *
     * @param list<int> $currentGlobalRoleIds
     * @param list<string> $currentStorePairs roleId:storeId
     */
    public function assertAssignmentsAvailable(
        Collection $globalRoles,
        array $storeRoles,
        array $currentGlobalRoleIds = [],
        array $currentStorePairs = [],
    ): void {
        foreach ($globalRoles as $role) {
            if (! $role instanceof Role || $this->isAvailable((string) $role->code, null)) {
                continue;
            }
            if (in_array((int) $role->id, $currentGlobalRoleIds, true)) {
                continue;
            }

            throw ValidationException::withMessages([
                'global_role_ids' => [__('job_roles.errors.module_unavailable', [
                    'role' => $this->presentation((string) $role->code)['name'],
                ])],
            ]);
        }

        foreach ($storeRoles as $assignment) {
            $role = $assignment['role'] ?? null;
            $storeId = (int) ($assignment['store_id'] ?? 0);
            if (! $role instanceof Role || $this->isAvailable((string) $role->code, $storeId)) {
                continue;
            }

            $pair = ((int) $role->id).':'.$storeId;
            if (in_array($pair, $currentStorePairs, true)) {
                continue;
            }

            throw ValidationException::withMessages([
                'store_roles' => [__('job_roles.errors.module_unavailable', [
                    'role' => $this->presentation((string) $role->code)['name'],
                ])],
            ]);
        }
    }

    /**
     * Server-side stale-assignment guard. Existing assignments remain in the DB,
     * but a catalog role that requires a disabled/unentitled module contributes no
     * permissions for that runtime scope.
     */
    public function roleContributesPermissions(string $code, ?int $storeId): bool
    {
        return ! $this->isBuiltIn($code) || $this->isAvailable($code, $storeId);
    }

    /** @return array<string,mixed> */
    public function presentation(string $code): array
    {
        $definition = $this->definition($code);
        if ($definition === null) {
            return [
                'code' => $code,
                'name' => $code,
                'description' => '',
                'responsibilities' => [],
                'permission_summary' => [],
                'domain' => __('job_roles.domains.custom'),
                'scope' => '',
                'level' => '',
                'required_modules' => [],
                'built_in' => false,
            ];
        }

        $root = 'job_roles.roles.'.$code;
        $permissionSummaries = [];
        foreach ($definition['permissions'] as $permission) {
            $key = 'job_roles.permission_summaries.'.$this->permissionSummaryKey($permission);
            $label = Lang::has($key) ? __($key) : $this->businessPermissionFallback($permission);
            if (! in_array($label, $permissionSummaries, true)) {
                $permissionSummaries[] = $label;
            }
        }

        return [
            ...$definition,
            'name' => Lang::has($root.'.name') ? __($root.'.name') : str($code)->headline()->toString(),
            'description' => Lang::has($root.'.description') ? __($root.'.description') : '',
            'responsibilities' => Lang::has($root.'.responsibilities')
                ? array_values((array) __($root.'.responsibilities'))
                : [],
            'permission_summary' => array_slice($permissionSummaries, 0, 8),
            'domain_label' => __('job_roles.domains.'.$definition['domain']),
            'scope_label' => __('job_roles.scopes.'.$definition['business_scope']),
            'level_label' => __('job_roles.levels.'.$definition['level']),
            'built_in' => true,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function presentationMap(): array
    {
        return $this->definitions()
            ->keys()
            ->mapWithKeys(fn (string $code): array => [$code => $this->presentation($code)])
            ->all();
    }

    /**
     * Idempotently creates/updates canonical system roles and their default
     * permission bundles. Custom roles are never touched.
     *
     * @return array{roles:int,permissions:int}
     */
    public function reconcile(): array
    {
        $permissions = Permission::query()->pluck('id', 'code');
        $allPermissionCodes = array_keys((array) config('permissions.abilities', []));
        $rolesTouched = 0;
        $grantsTouched = 0;

        DB::transaction(function () use ($permissions, $allPermissionCodes, &$rolesTouched, &$grantsTouched): void {
            foreach ($this->definitions() as $code => $definition) {
                $codes = $definition['permissions'] === ['*'] ? $allPermissionCodes : $definition['permissions'];
                $missing = array_values(array_diff($codes, $permissions->keys()->all()));
                if ($missing !== []) {
                    throw new RuntimeException('Built-in role ['.$code.'] references unknown permissions: '.implode(', ', $missing));
                }

                $englishName = Lang::get('job_roles.roles.'.$code.'.name', [], 'en');
                $englishDescription = Lang::get('job_roles.roles.'.$code.'.description', [], 'en');

                $role = Role::query()->firstOrNew(['code' => $code]);
                $role->forceFill([
                    'name' => is_string($englishName) && $englishName !== 'job_roles.roles.'.$code.'.name'
                        ? $englishName
                        : str($code)->headline()->toString(),
                    'description' => is_string($englishDescription) && $englishDescription !== 'job_roles.roles.'.$code.'.description'
                        ? $englishDescription
                        : $role->description,
                    'scope' => $definition['scope'],
                    'is_system' => true,
                    'is_active' => true,
                ])->save();

                $ids = collect($codes)->map(fn (string $permission): int => (int) $permissions[$permission])->all();
                $role->permissions()->sync($ids);
                $rolesTouched++;
                $grantsTouched += count($ids);
            }
        });

        return ['roles' => $rolesTouched, 'permissions' => $grantsTouched];
    }

    /** @return list<string> */
    private function defaultPermissions(string $code, array $definition): array
    {
        // Existing #96 roles retain their already-authoritative matrix. This maps
        // them into the richer catalog without silently narrowing live assignments.
        $legacy = (array) config('permissions.roles.'.$code, []);
        if ($legacy !== []) {
            return array_values(array_map('strval', $legacy));
        }

        return array_values(array_unique(array_map('strval', (array) ($definition['permissions'] ?? []))));
    }

    /** @param array<string,mixed> $definition */
    private function assertDefinition(array $definition): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]{2,79}$/D', (string) $definition['code'])) {
            throw new RuntimeException('Invalid built-in job role code.');
        }
        if (! in_array($definition['scope'], ['global', 'store'], true)) {
            throw new RuntimeException('Unsupported built-in role assignment scope ['.$definition['code'].'].');
        }
        if (! in_array($definition['level'], ['manager', 'supervisor', 'operator', 'specialist', 'viewer'], true)) {
            throw new RuntimeException('Unsupported built-in role level ['.$definition['code'].'].');
        }

        $knownModules = $this->modules->all()->keys()->all();
        $unknownModules = array_values(array_diff(
            [...$definition['required_modules'], ...$definition['optional_modules']],
            $knownModules,
        ));
        if ($unknownModules !== []) {
            throw new RuntimeException('Built-in role ['.$definition['code'].'] references unknown modules: '.implode(', ', $unknownModules));
        }

        $permissions = $definition['permissions'];
        if ($permissions === [] || (in_array('*', $permissions, true) && $permissions !== ['*'])) {
            throw new RuntimeException('Built-in role ['.$definition['code'].'] has an invalid permission bundle.');
        }
    }

    private function permissionSummaryKey(string $permission): string
    {
        $root = explode('.', $permission)[0] ?? $permission;

        return match ($root) {
            'b2b' => 'b2b',
            'smart_ordering' => 'restaurant',
            'procurement' => 'procurement',
            'inventory' => 'inventory',
            'finance' => 'finance',
            'reports' => 'reports',
            'orders' => 'orders',
            'customers' => 'customers',
            'support', 'whatsapp', 'chatbot' => 'customer_service',
            'catalog', 'lookups' => 'catalog',
            'drivers', 'deliveries', 'field_ops', 'territories', 'van' => 'field_operations',
            'promotions', 'notifications', 'coupons', 'live_ads' => 'marketing',
            'security', 'roles', 'users' => 'security',
            'platform', 'settings', 'system', 'translations', 'mobile_settings', 'push_settings', 'sms_settings', 'stores' => 'administration',
            default => 'general',
        };
    }

    private function businessPermissionFallback(string $permission): string
    {
        return str((string) preg_replace('/[._]+/', ' ', $permission))->headline()->toString();
    }
}
