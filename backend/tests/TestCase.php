<?php

namespace Tests;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function enableRestaurantSaasForTestStore(int $storeId): void
    {
        $registration = app(ModuleRegistry::class)
            ->syncManifest(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE);

        $registration->forceFill([
            'state' => ModuleState::ENABLED->value,
            'enabled_at' => $registration->enabled_at ?? now(),
            'state_changed_at' => now(),
        ])->save();

        DB::table('enterprise_module_entitlements')->updateOrInsert(
            [
                'module_code' => SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
                'scope_key' => EntitlementScope::STORE.':'.$storeId,
            ],
            [
                'scope_type' => EntitlementScope::STORE,
                'scope_id' => $storeId,
                'is_enabled' => true,
                'capabilities' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
