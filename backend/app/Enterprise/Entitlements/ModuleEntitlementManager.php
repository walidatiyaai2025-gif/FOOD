<?php

namespace App\Enterprise\Entitlements;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseModuleEntitlement;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

final class ModuleEntitlementManager
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly EntitlementScopeResolver $scopes,
        private readonly ModuleEntitlementResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>|null  $capabilities
     */
    public function set(
        string $moduleCode,
        EntitlementScope $scope,
        bool $enabled,
        ?array $capabilities = null,
        ?Authenticatable $actor = null,
    ): EnterpriseModuleEntitlement {
        $manifest = $this->registry->manifest($moduleCode);

        if ($this->scopes->lineage($scope) === []) {
            throw ValidationException::withMessages([
                'scope' => ['Enterprise entitlement scope does not exist or is inactive.'],
            ]);
        }

        $normalizedCapabilities = $this->normalizeCapabilities($capabilities);
        if ($normalizedCapabilities !== null) {
            $unknown = array_values(array_diff($normalizedCapabilities, $manifest->capabilities));
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'capabilities' => ['Entitlement contains capabilities not declared by the module manifest.'],
                ]);
            }
        }

        $existing = EnterpriseModuleEntitlement::query()
            ->where('module_code', $moduleCode)
            ->where('scope_key', $scope->key())
            ->first();

        $before = $existing === null ? null : $this->snapshot($existing);

        $entitlement = EnterpriseModuleEntitlement::query()->updateOrCreate(
            [
                'module_code' => $moduleCode,
                'scope_key' => $scope->key(),
            ],
            [
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'is_enabled' => $enabled,
                'capabilities' => $normalizedCapabilities,
            ],
        );

        $this->audit->record(
            'enterprise.module.entitlement_changed',
            $actor,
            $entitlement,
            $before,
            $this->snapshot($entitlement),
        );

        // Entitlement changes are immediately visible to every consumer that
        // shares the request-scoped resolver, including built-in role availability.
        $this->resolver->forgetModule($moduleCode);

        return $entitlement->refresh();
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>|null
     */
    private function normalizeCapabilities(?array $capabilities): ?array
    {
        if ($capabilities === null) {
            return null;
        }

        $normalized = array_values(array_unique(array_map(
            static fn (string $capability): string => trim($capability),
            $capabilities,
        )));

        if ($normalized === [] || in_array('', $normalized, true)) {
            throw ValidationException::withMessages([
                'capabilities' => ['Capability subset must contain non-empty capability codes.'],
            ]);
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EnterpriseModuleEntitlement $entitlement): array
    {
        return [
            'module_code' => $entitlement->module_code,
            'scope_key' => $entitlement->scope_key,
            'scope_type' => $entitlement->scope_type,
            'scope_id' => $entitlement->scope_id,
            'is_enabled' => $entitlement->is_enabled,
            'capabilities' => $entitlement->capabilities,
        ];
    }
}
