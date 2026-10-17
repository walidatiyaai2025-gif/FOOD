<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RestaurantSaasStoreFeatureService
{
    public function __construct(
        private readonly SmartOrderingEnterpriseEntitlementBridge $access,
        private readonly ModuleEntitlementManager $entitlements,
        private readonly ModuleRegistry $modules,
        private readonly ModuleLifecycleManager $lifecycle,
        private readonly OrganizationHierarchyService $organizations,
        private readonly RestaurantControlPlaneService $controlPlane,
        private readonly AuditLogger $audit,
    ) {}

    public function isEnabled(Store|int $store): bool
    {
        return $this->access->storeEnabled($store instanceof Store ? (int) $store->getKey() : $store);
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    public function enabledStoreIds(array $storeIds): array
    {
        return $this->access->enabledStoreIds($storeIds);
    }

    public function setEnabled(
        Store $store,
        bool $enabled,
        User $actor,
        ?Request $request = null,
    ): void {
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        $this->assertRetailStore($store);
        $before = $this->isEnabled($store);
        $this->modules->syncManifest(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE);

        if ($enabled) {
            $this->ensureModuleEnabled($actor);
            $this->controlPlane->provision($store, $actor, $request);
        } else {
            // The exact STORE entitlement requires a canonical hierarchy scope,
            // but disabling must not create, mutate or delete Restaurant data.
            $this->organizations->ensureDefaultScopeForStore($store, $actor);
        }

        $this->entitlements->set(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            new EntitlementScope(EntitlementScope::STORE, (int) $store->getKey()),
            $enabled,
            null,
            $actor,
        );

        $after = $this->isEnabled($store);
        if ($before !== $after) {
            $this->audit->record(
                'restaurant_saas.store_access.changed',
                $actor,
                $store,
                ['enabled' => $before],
                ['enabled' => $after],
                $request,
            );
        }
    }

    private function ensureModuleEnabled(User $actor): void
    {
        $this->modules->syncManifest(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $state = $this->modules->state(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE);
            if ($state === ModuleState::ENABLED) {
                return;
            }

            $next = match ($state) {
                ModuleState::NOT_INSTALLED => ModuleState::INSTALLED,
                ModuleState::INSTALLED => ModuleState::DISABLED,
                ModuleState::DISABLED, ModuleState::ERROR => ModuleState::ENABLING,
                ModuleState::ENABLING => ModuleState::ENABLED,
                ModuleState::SUSPENDED => ModuleState::ENABLED,
                default => ModuleState::ENABLED,
            };

            $this->lifecycle->transition(
                SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
                $next,
                $actor,
            );
        }

        abort_unless(
            $this->modules->isEnabled(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE),
            409,
            'Restaurant SaaS module could not be enabled.',
        );
    }

    private function assertRetailStore(Store $store): void
    {
        $retail = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $store->getKey())
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($retail, 404);
    }
}
