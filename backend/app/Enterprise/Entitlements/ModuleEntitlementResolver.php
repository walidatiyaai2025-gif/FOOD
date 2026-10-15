<?php

namespace App\Enterprise\Entitlements;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseModuleEntitlement;

final class ModuleEntitlementResolver
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly EntitlementScopeResolver $scopes,
    ) {}

    public function allows(
        string $moduleCode,
        EntitlementScope $scope,
        ?string $capability = null,
    ): bool {
        $memoKey = $moduleCode.'|'.$scope->key().'|'.($capability ?? '*');
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        if ($this->registry->isEnabled($moduleCode) === false) {
            return $this->memo[$memoKey] = false;
        }

        $manifest = $this->registry->manifest($moduleCode);
        if ($capability !== null && in_array($capability, $manifest->capabilities, true) === false) {
            return $this->memo[$memoKey] = false;
        }

        $lineage = $this->scopes->lineage($scope);
        if ($lineage === []) {
            return $this->memo[$memoKey] = false;
        }

        $keys = array_map(
            static fn (EntitlementScope $candidate): string => $candidate->key(),
            $lineage,
        );

        $rows = EnterpriseModuleEntitlement::query()
            ->where('module_code', $moduleCode)
            ->whereIn('scope_key', $keys)
            ->get()
            ->keyBy('scope_key');

        foreach ($lineage as $candidate) {
            /** @var EnterpriseModuleEntitlement|null $entitlement */
            $entitlement = $rows->get($candidate->key());
            if ($entitlement === null) {
                continue;
            }

            if ($entitlement->is_enabled === false) {
                return $this->memo[$memoKey] = false;
            }

            if ($capability === null || $entitlement->capabilities === null) {
                return $this->memo[$memoKey] = true;
            }

            $capabilities = is_array($entitlement->capabilities)
                ? $entitlement->capabilities
                : [];

            return $this->memo[$memoKey] = in_array($capability, $capabilities, true);
        }

        return $this->memo[$memoKey] = false;
    }
}
