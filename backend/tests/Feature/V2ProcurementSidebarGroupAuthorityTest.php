<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ProcurementSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_p238_authorized_procurement_group_opens_one_real_workbench_in_both_locales(): void
    {
        $this->enableProcurement();
        $store = $this->store('P238-PROCUREMENT-OWN');
        $actor = $this->operator($store);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $procurement = $groups->firstWhere('key', 'procurement');
            $this->assertNotNull($procurement);
            $this->assertSame('admin.nav_groups.procurement', $procurement['label']);

            $items = collect($procurement['children'])->keyBy('key');
            $this->assertCount(1, $items);
            $this->assertSame('admin.erp.procurement.index', $items['erp_procurement']['route']);
            $this->assertSame([], $items['erp_procurement']['params']);

            $catalog = $groups->firstWhere('key', 'catalog');
            $this->assertNotContains('erp_procurement', array_column($catalog['children'] ?? [], 'key'));
            $this->assertFalse($groups->contains('key', 'warehouses'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'suppliers',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.nav_groups.procurement'))
                ->assertSee('data-nav-group="procurement"', false);
        }
    }

    public function test_unprivileged_actor_has_no_procurement_group_or_direct_http_access(): void
    {
        $this->enableProcurement();
        $store = $this->store('P238-NO-GRANT');
        $actor = User::factory()->create(['is_active' => true]);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor))
            ->contains('key', 'procurement'));

        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))
            ->assertForbidden();
    }

    public function test_a_foreign_retail_store_cannot_be_opened_from_procurement(): void
    {
        $this->enableProcurement();
        $own = $this->store('P238-SCOPED-OWN');
        $foreign = $this->store('P238-SCOPED-FOREIGN');
        $actor = $this->operator($own);

        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $foreign->id,
                'tab' => 'suppliers',
            ]))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $own->id,
                'tab' => 'suppliers',
            ]))
            ->assertOk();
    }

    public function test_disabled_module_hides_group_and_refuses_direct_http_entry(): void
    {
        $this->enableProcurement();
        $store = $this->store('P238-DISABLED');
        $actor = $this->operator($store);

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::DISABLED);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->contains('key', 'procurement'));
        $this->actingAs($actor)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))
            ->assertForbidden();
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function operator(Store $store): User
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'procurement.suppliers.view'],
            ['name' => 'procurement.suppliers.view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        return $actor->fresh();
    }
}
