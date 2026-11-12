<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\EntitlementScopeResolver;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;

/**
 * Finance Workbench entitlement decisions use the exact operational store.
 *
 * The store lineage inherits legal-entity/company/platform grants but also
 * honors a more-specific store deny or capability restriction. Neither a
 * Sidebar link nor a broad legal-entity grant overrides that restriction.
 */
final class FinanceWorkbenchAccess
{
    /** @var list<string> */
    private const READ_CAPABILITIES = [
        'finance.foundation.read',
        'finance.gl.read',
        'finance.ar.read',
        'finance.ap.read',
    ];

    public function __construct(
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly EntitlementScopeResolver $scopes,
    ) {}

    public function canRead(User $actor, int $storeId): bool
    {
        if (! $actor->is_active || ! $actor->hasPermission('finance.view', $storeId)) {
            return false;
        }

        foreach (self::READ_CAPABILITIES as $capability) {
            if ($this->allowsAtStore($storeId, $capability)) {
                return true;
            }
        }

        return false;
    }

    public function canPerform(User $actor, int $storeId, string $permission, string $capability): bool
    {
        return $actor->is_active
            && $actor->hasPermission($permission, $storeId)
            && $this->allowsAtStore($storeId, $capability);
    }

    public function allowsAtStore(int $storeId, string $capability): bool
    {
        if ($storeId < 1) {
            return false;
        }

        $store = new EntitlementScope(EntitlementScope::STORE, $storeId);
        $legalEntity = null;

        foreach ($this->scopes->lineage($store) as $scope) {
            if ($scope->type === EntitlementScope::LEGAL_ENTITY) {
                $legalEntity = $scope;
                break;
            }
        }

        // Finance is a legal-entity ledger. A STORE-only entitlement must not
        // create Finance authority if the entity itself was never entitled.
        if ($legalEntity === null
            || ! $this->entitlements->allows(FinanceFoundationService::MODULE, $legalEntity, $capability)) {
            return false;
        }

        // A narrower STORE denial/restriction overrides parent entitlements.
        return $this->entitlements->allows(
            FinanceFoundationService::MODULE,
            $store,
            $capability,
        );
    }
}
