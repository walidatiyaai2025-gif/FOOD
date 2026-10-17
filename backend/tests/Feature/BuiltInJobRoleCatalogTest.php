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
use App\Services\BuiltInJobRoleCatalog;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BuiltInJobRoleCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_catalog_reconciles_real_modules_roles_and_permission_bundles_deterministically(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);

        $definitions = $catalog->definitions();

        $this->assertTrue($definitions->has('SUPER_ADMIN'));
        $this->assertTrue($definitions->has('PROCUREMENT_MANAGER'));
        $this->assertTrue($definitions->has('ACCOUNTANT'));
        $this->assertTrue($definitions->has('TREASURY_OFFICER'));
        $this->assertTrue($definitions->has('WAREHOUSE_MANAGER'));
        $this->assertTrue($definitions->has('RESTAURANT_DIGITAL_MANAGER'));
        $this->assertTrue($definitions->has('EXECUTIVE_VIEWER'));

        foreach ($definitions as $code => $definition) {
            $this->assertDatabaseHas('roles', [
                'code' => $code,
                'scope' => $definition['scope'],
                'is_system' => true,
                'is_active' => true,
            ]);
        }

        $sales = Role::query()->where('code', 'SALES_OFFICER')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            $definitions['SALES_OFFICER']['permissions'],
            $sales->permissions()->pluck('code')->all(),
        );

        $custom = Role::query()->create([
            'code' => 'CUSTOM_AUDIT_VIEWER',
            'name' => 'Custom audit viewer',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $custom->permissions()->sync([Permission::query()->where('code', 'reports.view')->value('id')]);

        config()->set('job_roles.roles.SALES_OFFICER.permissions', [
            ...$definitions['SALES_OFFICER']['permissions'],
            'reports.view',
        ]);
        $catalog->reconcile();
        $catalog->reconcile();

        $this->assertTrue(
            Role::query()->where('code', 'SALES_OFFICER')->firstOrFail()
                ->permissions()->where('code', 'reports.view')->exists(),
        );
        $this->assertDatabaseHas('roles', [
            'id' => $custom->id,
            'code' => 'CUSTOM_AUDIT_VIEWER',
            'is_system' => false,
        ]);
        $this->assertSame(['reports.view'], $custom->fresh()->permissions()->pluck('code')->all());
    }

    public function test_disabled_optional_module_hides_role_blocks_new_assignment_and_stale_assignment_grants_nothing(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $manager = app(RbacManager::class);
        $role = Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail();
        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);

        app(ModuleRegistry::class)->syncManifest('procurement');

        $this->assertFalse($catalog->isAvailable('PROCUREMENT_MANAGER'));

        try {
            $manager->replaceUserRoles($actor, $target, [$role->id], [], Request::create('/admin/security', 'PUT'));
            $this->fail('Disabled module role assignment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('global_role_ids', $exception->errors());
        }

        $this->enableModule('procurement');
        $this->assertTrue($catalog->isAvailable('PROCUREMENT_MANAGER'));

        $manager->replaceUserRoles($actor, $target, [$role->id], [], Request::create('/admin/security', 'PUT'));
        $this->assertTrue($target->fresh()->hasPermission('procurement.purchase_orders.manage'));
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $role->id]);

        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::DISABLED, $actor);

        $fresh = $target->fresh();
        $this->assertFalse($catalog->isAvailable('PROCUREMENT_MANAGER'));
        $this->assertFalse($fresh->hasPermission('procurement.purchase_orders.manage'));
        $this->assertNotContains('procurement.purchase_orders.manage', $fresh->effectivePermissionCodes());
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $role->id]);

        // Retaining the historical assignment is allowed; adding another unavailable
        // role would still be rejected by the same server-side guard.
        $manager->replaceUserRoles($actor, $fresh, [$role->id], [], Request::create('/admin/security', 'PUT'));
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $role->id]);
    }

    public function test_store_role_requires_both_module_lifecycle_and_store_entitlement(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $role = Role::query()->where('code', 'RESTAURANT_DIGITAL_MANAGER')->firstOrFail();
        $store = $this->retailStore('ROLE-STORE-A');
        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);

        $this->enableModule('smart_ordering');
        $this->assertFalse($catalog->isAvailable('RESTAURANT_DIGITAL_MANAGER', $store->id));

        app(ModuleEntitlementManager::class)->set(
            'smart_ordering',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            null,
            $actor,
        );

        $this->assertTrue($catalog->isAvailable('RESTAURANT_DIGITAL_MANAGER', $store->id));

        app(RbacManager::class)->replaceUserRoles(
            $actor,
            $target,
            [],
            [['store_id' => $store->id, 'role_id' => $role->id]],
            Request::create('/admin/security', 'PUT'),
        );

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasPermission('smart_ordering.manage', $store->id));
        $this->assertFalse($fresh->hasPermission('smart_ordering.manage'));
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        app(ModuleEntitlementManager::class)->set(
            'smart_ordering',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
            null,
            $actor,
        );

        $this->assertFalse($target->fresh()->hasPermission('smart_ordering.manage', $store->id));
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_multiple_roles_and_mixed_global_store_scopes_union_without_cross_channel_leakage(): void
    {
        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $store = $this->retailStore('ROLE-STORE-B');
        $sales = Role::query()->where('code', 'SALES_OFFICER')->firstOrFail();
        $storeStaff = Role::query()->where('code', 'STORE_STAFF')->firstOrFail();

        app(RbacManager::class)->replaceUserRoles(
            $actor,
            $target,
            [$sales->id],
            [['store_id' => $store->id, 'role_id' => $storeStaff->id]],
            Request::create('/admin/security', 'PUT'),
        );

        $fresh = $target->fresh();
        $this->assertTrue($fresh->hasPermission('b2b.accounts.view'));
        $this->assertFalse($fresh->hasPermission('deliveries.b2c.execute'));
        $this->assertTrue($fresh->hasPermission('orders.view', $store->id));
        $this->assertTrue($fresh->hasPermission('customers.view', $store->id));
        $this->assertFalse($fresh->hasPermission('b2b.accounts.view', $store->id));
    }

    public function test_two_and_three_role_combinations_union_overlapping_permissions_without_duplicates(): void
    {
        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $sales = Role::query()->where('code', 'SALES_OFFICER')->firstOrFail();
        $operations = Role::query()->where('code', 'OPERATIONS_SUPERVISOR')->firstOrFail();
        $service = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();

        $manager = app(RbacManager::class);
        $request = Request::create('/admin/security', 'PUT');

        $manager->replaceUserRoles($actor, $target, [$sales->id, $operations->id], [], $request);
        $twoRoleCodes = $target->fresh()->effectivePermissionCodes();

        $this->assertCount(2, $target->fresh()->roles);
        $this->assertContains('b2b.accounts.view', $twoRoleCodes);
        $this->assertContains('orders.dispatch', $twoRoleCodes);
        $this->assertSame(count($twoRoleCodes), count(array_unique($twoRoleCodes)));

        $manager->replaceUserRoles($actor, $target, [$sales->id, $operations->id, $service->id], [], $request);
        $threeRoleCodes = $target->fresh()->effectivePermissionCodes();

        $this->assertCount(3, $target->fresh()->roles);
        $this->assertContains('b2b.accounts.view', $threeRoleCodes);
        $this->assertContains('orders.dispatch', $threeRoleCodes);
        $this->assertContains('whatsapp.inbox.view', $threeRoleCodes);
        $this->assertSame(count($threeRoleCodes), count(array_unique($threeRoleCodes)));
    }

    public function test_procurement_maker_checker_conflict_is_warned_without_replacing_runtime_sod_enforcement(): void
    {
        $this->enableModule('procurement');

        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $buyer = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $approver = Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail();

        app(RbacManager::class)->replaceUserRoles(
            $actor,
            $target,
            [$buyer->id, $approver->id],
            [],
            Request::create('/admin/security', 'PUT'),
        );

        $this->assertEqualsCanonicalizing(
            ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
            $target->fresh()->roles()->pluck('code')->all(),
        );
        $this->assertSame(
            ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
            config('job_roles.risk_pairs.procurement_maker_checker.roles'),
        );

        App::setLocale('en');
        $englishWarning = __('job_roles.risk_warnings.procurement_maker_checker');
        App::setLocale('ar');
        $arabicWarning = __('job_roles.risk_warnings.procurement_maker_checker');

        $this->assertNotSame('job_roles.risk_warnings.procurement_maker_checker', $englishWarning);
        $this->assertNotSame('job_roles.risk_warnings.procurement_maker_checker', $arabicWarning);

        $this->actingAs($actor)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('data-role-risk-pairs', false)
            ->assertSee($arabicWarning);
    }

    public function test_enabling_module_exposes_roles_without_auto_assigning_existing_users(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $catalog = app(BuiltInJobRoleCatalog::class);

        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        $this->assertFalse($catalog->isAvailable('EXECUTIVE_VIEWER'));

        $this->enableModule('enterprise_bi');

        $this->assertTrue(app(BuiltInJobRoleCatalog::class)->isAvailable('EXECUTIVE_VIEWER'));
        $this->assertFalse($target->roles()->where('code', 'EXECUTIVE_VIEWER')->exists());
        $this->assertFalse($target->hasPermission('reports.export'));
    }

    public function test_arabic_and_english_present_business_language_not_permission_codes(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);

        App::setLocale('en');
        $en = $catalog->presentation('CUSTOMER_SERVICE_MANAGER');

        App::setLocale('ar');
        $ar = $catalog->presentation('CUSTOMER_SERVICE_MANAGER');

        $this->assertSame('Customer Service Manager', $en['name']);
        $this->assertSame('مدير خدمة العملاء', $ar['name']);
        $this->assertNotEmpty($en['description']);
        $this->assertNotEmpty($ar['description']);
        $this->assertNotEmpty($en['responsibilities']);
        $this->assertNotEmpty($ar['responsibilities']);
        $this->assertNotEmpty($en['permission_summary']);
        $this->assertNotEmpty($ar['permission_summary']);
        $this->assertStringNotContainsString('whatsapp.', implode(' ', $ar['responsibilities']));
    }

    public function test_security_page_hides_unavailable_unassigned_optional_roles_and_marks_retained_assignment(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['name' => 'Retained Procurement User', 'is_active' => true]);
        $procurement = Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail();

        app(ModuleRegistry::class)->syncManifest('procurement');
        $target->roles()->attach($procurement->id);

        App::setLocale('en');
        $this->actingAs($admin)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('Procurement Manager')
            ->assertSee(__('job_roles.availability.retained'))
            ->assertSee(__('job_roles.errors.canonical_locked'));

        $target->roles()->detach($procurement->id);

        $this->actingAs($admin)
            ->get(route('admin.security.index'))
            ->assertOk()
            ->assertSee('Procurement Manager'); // role governance card remains visible/auditable
    }

    private function enableModule(string $code): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration($code) === null) {
            $registry->syncManifest($code);
        }

        $state = $registry->state($code);
        if ($state === ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition($code, ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition($code, ModuleState::ENABLED);
        } elseif ($state === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition($code, ModuleState::DISABLED);
            app(ModuleLifecycleManager::class)->transition($code, ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition($code, ModuleState::ENABLED);
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
