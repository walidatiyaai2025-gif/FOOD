<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SmartOrderingEnterpriseEntitlementCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('smart_ordering.enterprise_entitlement_bridge', false);
    }

    public function test_manifest_capabilities_remain_in_lockstep_with_legacy_smart_ordering_modules(): void
    {
        $this->assertSame(
            Module::values(),
            config('enterprise_modules.modules.'.SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE.'.capabilities'),
        );
    }

    public function test_store_master_is_required_even_when_capability_bridge_is_disabled(): void
    {
        [$site, $admin] = $this->site('SO-BRIDGE-OFF');
        app(SmartOrderingSiteService::class)->setEntitlement($admin, $site, Module::PUBLIC_MENU, true);

        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));

        $this->scopeStore((int) $site->store_id);
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLING,
        );
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLED,
        );
        $this->setEnterpriseEntitlement((int) $site->store_id, true);

        $this->assertTrue(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));
    }

    public function test_enabled_bridge_fails_closed_while_enterprise_module_is_disabled(): void
    {
        [$site, $admin] = $this->site('SO-BRIDGE-MODULE-OFF');
        app(SmartOrderingSiteService::class)->setEntitlement($admin, $site, Module::PUBLIC_MENU, true);
        config()->set('smart_ordering.enterprise_entitlement_bridge', true);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_module_entitlements')
                || str_contains($sql, 'enterprise_store_scopes'),
        ));
    }

    public function test_enterprise_store_entitlement_can_narrow_but_never_expand_legacy_access(): void
    {
        [$site, $admin] = $this->site('SO-BRIDGE-ALLOW');
        $service = app(SmartOrderingSiteService::class);
        $service->setEntitlement($admin, $site, Module::PUBLIC_MENU, true);

        $this->scopeStore((int) $site->store_id);
        $this->enableEnterpriseBridge();
        $this->setEnterpriseEntitlement((int) $site->store_id, true, [Module::PUBLIC_MENU->value]);

        $this->assertTrue(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));
        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::ORDERING));

        $service->setEntitlement($admin, $site, Module::PUBLIC_MENU, false);

        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU));
    }

    public function test_enterprise_entitlement_is_store_scoped_and_explicit_deny_wins(): void
    {
        [$siteA, $adminA] = $this->site('SO-BRIDGE-A');
        [$siteB, $adminB] = $this->site('SO-BRIDGE-B');
        $service = app(SmartOrderingSiteService::class);
        $service->setEntitlement($adminA, $siteA, Module::PUBLIC_MENU, true);
        $service->setEntitlement($adminB, $siteB, Module::PUBLIC_MENU, true);

        $this->scopeStore((int) $siteA->store_id);
        $this->scopeStore((int) $siteB->store_id);
        $this->enableEnterpriseBridge();
        $this->setEnterpriseEntitlement((int) $siteA->store_id, true, [Module::PUBLIC_MENU->value]);

        $this->assertTrue(app(SmartOrderingRuntimePolicy::class)->entitled($siteA, Module::PUBLIC_MENU));
        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($siteB, Module::PUBLIC_MENU));

        $this->setEnterpriseEntitlement((int) $siteA->store_id, false);

        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->entitled($siteA, Module::PUBLIC_MENU));
    }

    /**
     * @return array{0:SmartOrderingSite,1:User}
     */
    private function site(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => 'Restaurant Admin',
            'email' => 'bridge-'.$storeId.'-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin), $admin];
    }

    private function scopeStore(int $storeId): void
    {
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
        );
    }

    private function enableEnterpriseBridge(): void
    {
        config()->set('smart_ordering.enterprise_entitlement_bridge', true);

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLING,
        );
        $lifecycle->transition(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            ModuleState::ENABLED,
        );
    }

    /**
     * @param  list<string>|null  $capabilities
     */
    private function setEnterpriseEntitlement(
        int $storeId,
        bool $enabled,
        ?array $capabilities = null,
    ): void {
        app(ModuleEntitlementManager::class)->set(
            SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE,
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            $enabled,
            $capabilities,
        );
    }
}
