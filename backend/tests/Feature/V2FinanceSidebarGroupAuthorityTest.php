<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2609 / P279: the Finance business Sidebar group projects existing
 * independently authorized destinations; it is not an authorization grant.
 */
final class V2FinanceSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        foreach (['finance', 'treasury'] as $module) {
            config()->set(
                'enterprise_module_readiness.certified_versions.'.$module,
                app(ModuleRegistry::class)->manifest($module)->version,
            );
        }
    }

    public function test_finance_group_exposes_only_three_real_scoped_destinations_in_both_locales(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P279-OWN', 'B2C');
        $this->enableModule($actor, $context, FinanceFoundationService::MODULE);
        $this->enableModule($actor, $context, TreasuryReceiptService::MODULE);

        foreach (['ar' => ['rtl', 'المالية والخزينة'], 'en' => ['ltr', 'Finance & Treasury']] as $locale => [$direction, $heading]) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = $this->groups($actor);
            $matches = $groups->where('key', 'finance');
            $this->assertCount(1, $matches);

            $group = $matches->first();
            $this->assertSame('admin.nav_groups.finance', $group['label']);
            $this->assertSame($heading, __($group['label']));

            $expected = [
                'treasury_master' => 'admin.treasury.master.index',
                'erp_finance' => 'admin.erp.finance.index',
                'erp_treasury' => 'admin.erp.treasury.index',
            ];
            $items = collect($group['children'])->keyBy('key');
            $this->assertSame(array_keys($expected), $items->keys()->all());

            foreach ($expected as $key => $routeName) {
                $this->assertSame($routeName, $items[$key]['route']);
                $this->assertTrue(Route::has($routeName));
                $this->assertNotSame($items[$key]['label'], __($items[$key]['label']));
                $this->assertSame(
                    1,
                    $groups->flatMap(static fn (array $entry): array => $entry['children'])
                        ->where('key', $key)->count(),
                );

                $this->actingAs($actor->fresh())
                    ->get(route($routeName, ['store_id' => $store->id], false))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_disabled_modules_do_not_create_dead_finance_or_treasury_workbench_links(): void
    {
        [$actor, $store] = $this->reader('V2-P279-DISABLED', 'B2C');

        $group = $this->groups($actor)->firstWhere('key', 'finance');
        $this->assertIsArray($group);
        $this->assertSame(['treasury_master'], collect($group['children'])->pluck('key')->all());

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', ['store_id' => $store->id], false))
            ->assertOk();
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id], false))
            ->assertForbidden();
    }

    public function test_uncertified_finance_module_is_not_advertised_as_an_operational_workbench(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P279-UNCERTIFIED', 'B2C');
        $this->enableModule($actor, $context, FinanceFoundationService::MODULE);

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $group = $this->groups($actor)->firstWhere('key', 'finance');

        $this->assertIsArray($group);
        $this->assertSame(['treasury_master'], collect($group['children'])->pluck('key')->all());
        $this->assertTrue(Route::has('admin.erp.finance.index'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $store->id));
    }

    public function test_explicit_finance_deny_removes_group_and_blocks_all_three_direct_routes(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P279-DENY', 'B2C');
        $this->enableModule($actor, $context, FinanceFoundationService::MODULE);
        $this->enableModule($actor, $context, TreasuryReceiptService::MODULE);
        $this->assertNotNull($this->groups($actor)->firstWhere('key', 'finance'));

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertNull($this->groups($actor)->firstWhere('key', 'finance'));

        foreach ([
            'admin.treasury.master.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
        ] as $routeName) {
            $this->actingAs($actor->fresh())
                ->get(route($routeName, ['store_id' => $store->id], false))
                ->assertForbidden();
        }
    }

    public function test_eligible_reader_cannot_open_another_retail_or_wholesale_store(): void
    {
        [$actor, $store, $context] = $this->reader('V2-P279-SCOPE', 'B2C');
        $foreignRetail = $this->store('V2-P279-OTHER-RETAIL', 'B2C');
        $foreignWholesale = $this->store('V2-P279-OTHER-WHOLESALE', 'B2B');
        $this->enableModule($actor, $context, FinanceFoundationService::MODULE);
        $this->enableModule($actor, $context, TreasuryReceiptService::MODULE);
        $this->assertNotNull($this->groups($actor)->firstWhere('key', 'finance'));

        foreach ([$foreignRetail, $foreignWholesale] as $foreign) {
            $this->actingAs($actor)
                ->get(route('admin.treasury.master.index', ['store_id' => $foreign->id], false))
                ->assertNotFound();
            $this->actingAs($actor)
                ->get(route('admin.erp.finance.index', ['store_id' => $foreign->id], false))
                ->assertNotFound();
            $this->actingAs($actor)
                ->get(route('admin.erp.treasury.index', ['store_id' => $foreign->id], false))
                ->assertForbidden();
        }

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', ['store_id' => $store->id], false))
            ->assertOk();
    }

    public function test_roleless_and_inactive_users_have_no_finance_group_or_direct_read_access(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        [$inactive, $store] = $this->reader('V2-P279-INACTIVE', 'B2C');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->assertNull($this->groups($actor)->firstWhere('key', 'finance'));
            $this->actingAs($actor)
                ->get(route('admin.treasury.master.index', ['store_id' => $store->id], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
                ->assertForbidden();
        }
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function groups(User $actor): \Illuminate\Support\Collection
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function reader(string $code, string $channel): array
    {
        $store = $this->store($code, $channel);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, app(TenantContextResolver::class)->retail($actor, $store->id)];
    }

    private function store(string $code, string $channel): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableModule(User $actor, ResolvedTenantContext $context, string $module): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled($module)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition($module, ModuleState::ENABLING, $actor);
            $lifecycle->transition($module, ModuleState::ENABLED, $actor);
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $manager = app(ModuleEntitlementManager::class);

        if ($module === FinanceFoundationService::MODULE) {
            $manager->set(
                $module,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                true,
                ['finance.foundation.read', 'finance.gl.read', 'finance.ar.read', 'finance.ap.read'],
                $actor,
            );
        }

        $manager->set(
            $module,
            new EntitlementScope(EntitlementScope::STORE, $context->storeId),
            true,
            null,
            $actor,
        );
    }
}
