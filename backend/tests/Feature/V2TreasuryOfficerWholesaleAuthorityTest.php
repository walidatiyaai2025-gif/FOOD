<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #2699 / T122R19 — Treasury Officer needs an effective Wholesale entry,
 * but never independent Retail, finance approval or security authority.
 */
final class V2TreasuryOfficerWholesaleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_builtin_treasury_officer_has_a_minimal_module_gated_wholesale_role_and_bilingual_governance(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('TREASURY_OFFICER');
        $this->assertIsArray($definition);
        $this->assertSame('treasury', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame(['treasury'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.manage', 'reports.view'],
            $definition['permissions'],
        );

        $role = Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            $definition['permissions'],
            $role->permissions()->pluck('code')->all(),
        );

        $this->assertContains('TREASURY_OFFICER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('TREASURY_OFFICER', config('admin.channels.b2c.global_roles'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('TREASURY_OFFICER');
            $this->assertNotEmpty($presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
            $this->assertNotSame('TREASURY_OFFICER', $presentation['name']);
        }
    }

    public function test_treasury_officer_has_real_wholesale_cashbox_navigation_and_http_but_no_retail_or_approval_access(): void
    {
        $wholesale = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($wholesale);
        $retail = $this->retailStore('V2-P2699-RETAIL');
        $actor = $this->officer();

        $this->enableTreasury();
        $this->assertTrue(app(BuiltInJobRoleCatalog::class)->isAvailable('TREASURY_OFFICER'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage'));

        foreach (['finance.approve', 'finance.post', 'reports.export', 'roles.manage', 'orders.dispatch'] as $permission) {
            $this->assertFalse($actor->fresh()->hasPermission($permission), $permission.' must not be inherited');
        }

        $scope = app(OperationalTenantScope::class);
        $this->assertContains($wholesale->id, $scope->allowedStoreIds($actor->fresh(), 'finance.view', 'b2b'));
        $this->assertNotContains($retail->id, $scope->allowedStoreIds($actor->fresh(), 'finance.view'));
        $this->assertSame([], $scope->allowedStoreIds($actor->fresh(), 'finance.view', 'b2c'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $navigation = app(AdminNavigation::class);
            $this->assertTrue($navigation->canAccess($actor->fresh(), 'b2b'));
            $this->assertFalse($navigation->canAccess($actor->fresh(), 'b2c'));

            $groups = collect($navigation->groupsFor($actor->fresh()));
            $items = $groups->flatMap(static fn (array $group): array => $group['children'])->keyBy('key');
            $this->assertTrue($items->has('treasury_master'), 'Treasury link must be visible: '.$locale);
            $this->assertSame('admin.treasury.master.index', $items['treasury_master']['route']);
            $this->assertTrue(Route::has($items['treasury_master']['route']));
            $this->assertFalse($items->has('sidebar_role_access'));
            $this->assertFalse($items->has('b2c_orders'));
            $this->assertFalse($items->has('erp_finance'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-mobile-web-page="treasury-master"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['store_id' => $retail->id], false))
                ->assertNotFound();
        }

        $this->actingAs($actor->fresh())
            ->post(route('admin.treasury.cashboxes.store', [], false), $this->cashboxPayload($retail, 'V2-P2699-RETAIL-BLOCKED'))
            ->assertNotFound();
        $this->assertDatabaseMissing('enterprise_cashboxes', ['code' => 'V2-P2699-RETAIL-BLOCKED']);

        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.module', ['module' => 'finance'], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.reports.export', ['report' => 'orders', 'format' => 'xlsx'], false))
            ->assertForbidden();

        $this->actingAs($actor->fresh())
            ->post(route('admin.treasury.cashboxes.store', [], false), $this->cashboxPayload($wholesale, 'V2-P2699-WHOLESALE'))
            ->assertRedirect(route('admin.treasury.master.index', ['tab' => 'cashboxes']));
        $this->assertDatabaseHas('enterprise_cashboxes', [
            'store_id' => $wholesale->id,
            'code' => 'V2-P2699-WHOLESALE',
        ]);
        $createdId = EnterpriseCashbox::query()->where('code', 'V2-P2699-WHOLESALE')->value('id');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.cashbox.created',
            'auditable_id' => $createdId,
        ]);
    }

    public function test_disabled_module_and_explicit_denies_fail_closed_without_erasing_historical_role_assignment(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->officer();
        $roleId = Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail()->id;

        app(ModuleRegistry::class)->syncManifest('treasury');
        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('TREASURY_OFFICER'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->actingAs($actor)->get(route('admin.treasury.master.index', [], false))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.reports.index', [], false))->assertForbidden();

        $this->enableTreasury();
        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', [], false))
            ->assertOk();

        $permissionId = Permission::query()->where('code', 'finance.manage')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', [], false))
            ->assertOk();
        $this->actingAs($actor->fresh())
            ->post(route('admin.treasury.cashboxes.store', [], false), [
                'store_id' => $wholesaleId,
                'code' => 'V2-P2699-DENIED',
                'name' => 'Must not create',
                'operator_visibility' => 'store',
                'approval_visibility' => 'store',
                'is_active' => 1,
            ])->assertNotFound();
        $this->assertDatabaseMissing('enterprise_cashboxes', ['code' => 'V2-P2699-DENIED']);

        app(ModuleLifecycleManager::class)->transition('treasury', ModuleState::DISABLED);
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $roleId]);
        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('treasury_master', $keys);
        $this->actingAs($actor->fresh())->get(route('admin.treasury.master.index', [], false))->assertForbidden();
    }

    public function test_inactive_and_roleless_users_cannot_access_treasury_from_direct_http(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $this->enableTreasury();

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->officer();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.treasury.master.index', [], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.reports.index', [], false))
                ->assertForbidden();
        }
    }

    private function officer(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(string $code): Store
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

    /** @return array<string, int|string> */
    private function cashboxPayload(Store $store, string $code): array
    {
        return [
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Treasury Officer cashbox',
            'operator_visibility' => 'store',
            'approval_visibility' => 'store',
            'is_active' => 1,
        ];
    }

    private function enableTreasury(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('treasury') === null) {
            $registry->syncManifest('treasury');
        }

        $state = $registry->state('treasury');
        if ($state === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition('treasury', ModuleState::DISABLED);
            $state = ModuleState::DISABLED;
        }

        if ($state === ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition('treasury', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('treasury', ModuleState::ENABLED);
        }
    }
}
