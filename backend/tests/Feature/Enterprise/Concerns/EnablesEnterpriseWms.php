<?php

namespace Tests\Feature\Enterprise\Concerns;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;

trait EnablesEnterpriseWms
{
    protected function enableEnterpriseWmsForStore(int $storeId): void
    {
        $store = Store::query()->findOrFail($storeId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('inventory_wms');

        $lifecycle = app(ModuleLifecycleManager::class);
        if ($registry->state('inventory_wms') !== ModuleState::ENABLED) {
            if ($registry->state('inventory_wms') !== ModuleState::DISABLED) {
                $lifecycle->transition('inventory_wms', ModuleState::DISABLED);
            }

            $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
            $lifecycle->transition('inventory_wms', ModuleState::ENABLED);
        }

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            true,
        );
    }
}
