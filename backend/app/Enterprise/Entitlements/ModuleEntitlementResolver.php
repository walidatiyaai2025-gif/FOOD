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

    public function forgetModule(string $moduleCode): void
    {
        $prefix = $moduleCode.'|';

        foreach (array_keys($this->memo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->memo[$key]);
            }
        }
    }

    public function allows(
        string $moduleCode,
        EntitlementScope $scope,
        ?string $capability = null,
    ): bool {
        // Lifecycle state is mutable and must remain authoritative even when an
        // entitlement decision was memoized earlier in the request.
        if ($this->registry->isEnabled($moduleCode) === false) {
            return false;
        }

        $memoKey = $moduleCode.'|'.$scope->key().'|'.($capability ?? '*');
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
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

            $rawCapabilities = $entitlement->getRawOriginal('capabilities');
            if ($capability === null || $rawCapabilities === null) {
                return $this->memo[$memoKey] = true;
            }

            if (is_string($rawCapabilities) === false) {
                return $this->memo[$memoKey] = false;
            }

            $decodedCapabilities = json_decode($rawCapabilities, true);
            if (is_array($decodedCapabilities) === false) {
                return $this->memo[$memoKey] = false;
            }

            $capabilities = [];
            foreach ($decodedCapabilities as $value) {
                if (is_string($value)) {
                    $capabilities[] = $value;
                }
            }

            return $this->memo[$memoKey] = in_array($capability, $capabilities, true);
        }

        return $this->memo[$memoKey] = false;
    }
}
