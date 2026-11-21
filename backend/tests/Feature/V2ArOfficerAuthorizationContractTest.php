<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2696 / T122R16: accounts receivable authority is Finance-module
 * gated, cannot approve or post journals, and cannot cross Retail stores.
 */
final class V2ArOfficerAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_ar_officer_uses_the_canonical_bilingual_least_privilege_role(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('AR_OFFICER');

        $this->assertIsArray($definition);
        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['finance'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.manage', 'reports.view'],
            $definition['permissions'],
        );

        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing($definition['permissions'], $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مسؤول حسابات العملاء', 'en' => 'Accounts Receivable Officer'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('AR_OFFICER');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }

        foreach ([
            'finance.post',
            'finance.approve',
            'reports.export',
            'security.view',
            'roles.manage',
            'system.update',
            'orders.dispatch',
            'inventory.adjust',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $definition['permissions']);
        }
    }

    public function test_finance_lifecycle_revokes_ar_access_without_deleting_role_assignment(): void
    {
        app(ModuleRegistry::class)->syncManifest('finance');
        $actor = $this->officer();
        $catalog = app(BuiltInJobRoleCatalog::class);
        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();

        $this->assertFalse($catalog->isAvailable('AR_OFFICER'));
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [], false))->assertForbidden();

        $this->enableFinance();
        $this->assertTrue($catalog->isAvailable('AR_OFFICER'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();

        app(ModuleLifecycleManager::class)->transition('finance', ModuleState::DISABLED);

        $this->assertFalse($catalog->isAvailable('AR_OFFICER'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [], false))
            ->assertForbidden();
    }

    public function test_explicit_manage_deny_does_not_revoke_receivables_read_access(): void
    {
        $this->enableFinance();
        $actor = $this->officer();

        $this->assertTrue($actor->hasPermission('finance.manage'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
    }

    public function test_global_ar_officer_cannot_use_its_finance_role_on_an_unrelated_retail_store(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-AR-OFFICER-RETAIL',
            'name' => 'Unrelated retail tenant for AR regression',
            'is_active' => true,
        ]);

        $retailOperations = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $retailOperations->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
    }

    public function test_ar_officer_with_a_retail_finance_assignment_cannot_borrow_b2c_ar_authority(): void
    {
        $this->enableFinance();
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-AR-OFFICER-CROSS-CHANNEL',
            'name' => 'Cross-channel AR guard retail store',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $scope['legal_entity_id']),
            true,
            [
                'finance.ar.read',
                'finance.ar.manage',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.credit_terms.read',
                'finance.credit_terms.manage',
            ],
        );

        $retailFinance = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $actor = $this->officer();
        $retailOnly = User::factory()->create(['is_active' => true]);

        foreach ([$actor, $retailOnly] as $principal) {
            DB::table('user_store_roles')->insert([
                'user_id' => $principal->id,
                'store_id' => $store->id,
                'role_id' => $retailFinance->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $access = app(FinanceWorkbenchAccess::class);
        $actor = $actor->fresh();
        $retailOnly = $retailOnly->fresh();
        // Both identities have a real, effective store Finance grant. The
        // AR_OFFICER duty is the sole difference: it may not borrow B2C scope.
        $this->assertTrue($actor->hasPermission('finance.view', $store->id));
        $this->assertTrue($actor->hasPermission('finance.manage', $store->id));
        $this->assertTrue($retailOnly->hasPermission('finance.view', $store->id));
        $this->assertTrue($access->canRead($retailOnly, $store->id));
        $this->assertTrue($access->canReadCapability(
            $retailOnly, $store->id, 'finance.opening_balances.read',
        ));
        $this->assertTrue($access->canReadCapability(
            $retailOnly, $store->id, 'finance.credit_terms.read',
        ));
        $this->assertFalse($access->canReadCapability(
            $retailOnly, $store->id, 'finance.unknown.read',
        ));
        $this->assertTrue($access->canPerform(
            $retailOnly, $store->id, 'finance.manage', 'finance.credit_terms.manage',
        ));
        $this->assertFalse($access->canPerform(
            $retailOnly, $store->id, 'finance.view', 'finance.ar.manage',
        ));
        $this->assertFalse($access->canPerform(
            $retailOnly, $store->id, 'finance.manage', 'finance.unknown.manage',
        ));
        $this->assertFalse($access->canRead($actor, $store->id));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ], false))->assertForbidden();
        }

        // Removing the AR duty does not silently revoke the legitimate
        // store-scoped Retail Finance role: this is a specific SoD ceiling.
        $actor->roles()->detach(Role::query()->where('code', 'AR_OFFICER')->firstOrFail());
        $this->assertTrue($access->canRead($actor->fresh(), $store->id));
    }

    public function test_deactivated_officer_role_cannot_keep_finance_privileges(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();

        $this->assertTrue($actor->hasPermission('finance.view'));
        $role->forceFill(['is_active' => false])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);
    }

    /**
     * A global AR operator can prepare receivables only through an authorized
     * AR workflow. It cannot forge generic GL posting or maker/checker
     * decisions through direct HTTP requests, regardless of locale.
     */
    public function test_ar_officer_cannot_post_or_approve_general_ledger_operations_by_direct_http(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        // CoreReferenceSeeder provides the B2B store type, not a live wholesale store.
        // Seed an isolated active context so the negative HTTP contract cannot
        // depend on production fixture data or accidentally skip its assertions.
        $wholesaleTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertGreaterThan(0, $wholesaleTypeId);
        $wholesaleStore = Store::query()->create([
            'store_type_id' => $wholesaleTypeId,
            'code' => 'V2-AR-OFFICER-WHOLESALE',
            'name' => 'Isolated wholesale context for AR GL denial',
            'is_active' => true,
        ]);
        $wholesaleId = (int) $wholesaleStore->id;

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'ledger' => DB::table('enterprise_finance_ledger_entries')->count(),
        ];

        $attempts = [
            ['admin.erp.finance.journals.store', []],
            ['admin.erp.finance.journals.approval', ['journal' => 999999]],
            ['admin.erp.finance.journals.post', ['journal' => 999999]],
            ['admin.erp.finance.opening-balances.approval', ['batch' => 999999]],
            ['admin.erp.finance.opening-balances.post', ['batch' => 999999]],
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();

            $this->assertTrue($principal->hasPermission('finance.manage'));
            $this->assertFalse($principal->hasPermission('finance.post'));
            $this->assertFalse($principal->hasPermission('finance.approve'));

            foreach ($attempts as [$routeName, $routeParameters]) {
                $this->assertTrue(Route::has($routeName), $routeName);
                $response = $this->actingAs($principal)->post(
                    route($routeName, $routeParameters, false),
                    ['store_id' => $wholesaleId, 'idempotency_key' => '2696-gl-denied'],
                );
                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 404],
                    "{$routeName}: {$locale}: direct GL operation must remain denied.",
                );
            }
        }

        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['ledger'], DB::table('enterprise_finance_ledger_entries')->count());
    }

    public function test_standalone_ar_officer_has_only_b2b_receivables_capability_and_explicit_store_deny_wins(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-AR-STANDALONE-STORE',
            'name' => 'Wholesale AR scoped positive store',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $scope['legal_entity_id']),
            true,
            ['finance.ar.read', 'finance.ar.manage', 'finance.gl.read', 'finance.ap.read'],
        );

        $access = app(FinanceWorkbenchAccess::class);
        $actor = $actor->fresh();

        // The generic B2B role gate stays CLOSED. No broad Finance channel
        // admission is needed to authorize an explicitly entitled AR duty.
        $this->assertTrue($actor->hasPermission('finance.view'));
        $this->assertFalse($actor->hasPermission('finance.view', $store->id));
        $this->assertTrue($access->canRead($actor, $store->id));
        $this->assertTrue($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertTrue($access->canPerform($actor, $store->id, 'finance.manage', 'finance.ar.manage'));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.view', 'finance.ar.manage',
        ));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.read',
        ));
        foreach (['finance.foundation.read', 'finance.gl.read', 'finance.ap.read'] as $capability) {
            $this->assertFalse($access->canReadCapability($actor, $store->id, $capability));
        }
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.gl.manage',
        ));

        // Store-scoped DENY beats an otherwise valid global AR role and
        // inherited legal-entity entitlement; historical assignment remains.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($access->canRead($actor->fresh(), $store->id));
        $this->assertFalse($access->canReadCapability($actor->fresh(), $store->id, 'finance.ar.read'));
    }

    public function test_ar_officer_finance_access_remains_ar_only_with_a_second_b2b_channel_role(): void
    {
        $this->enableFinance();
        // ERP HTTP requires version-scoped operational readiness in addition
        // to lifecycle ENABLED. Certify only the isolated test request; never
        // bypass the real production readiness middleware or change config.
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
        $actor = $this->officer();

        // FINANCE admits the actor to B2B and independently grants finance.manage.
        // The concurrent AR_OFFICER duty must fail closed for GL/AP operations
        // rather than inheriting this broad permission until P127 SoD resolves.
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-AR-OFFICER-SCOPE',
            'name' => 'AR capability isolation store',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);

        $entitlements = app(ModuleEntitlementManager::class);
        $legalEntity = new EntitlementScope(
            EntitlementScope::LEGAL_ENTITY,
            (int) $scope['legal_entity_id'],
        );
        $entitlements->set(FinanceFoundationService::MODULE, $legalEntity, true, [
            'finance.ar.read',
            'finance.ar.manage',
            'finance.gl.read',
            'finance.gl.manage',
            'finance.ap.read',
            'finance.ap.manage',
            'finance.foundation.read',
            'finance.foundation.manage',
            'finance.approvals.manage',
        ]);

        $actor = $actor->fresh();
        $access = app(FinanceWorkbenchAccess::class);
        $this->assertTrue($actor->hasPermission('finance.view', $store->id));
        $this->assertTrue($actor->hasPermission('finance.manage', $store->id));
        $this->assertTrue($access->canRead($actor, $store->id));
        $this->assertTrue($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        foreach ([
            'finance.gl.read',
            'finance.ap.read',
            'finance.foundation.read',
            'finance.opening_balances.read',
            'finance.unknown.read',
        ] as $capability) {
            $this->assertFalse(
                $access->canReadCapability($actor, $store->id, $capability),
                "AR_OFFICER must not inherit {$capability} by combining Finance roles.",
            );
        }
        $this->assertTrue($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        // Reach a real B2B store through a separately granted Finance
        // channel role. The AR tab must read with AR-specific entitlement,
        // whereas a direct GL create must be rejected at authorization (403)
        // rather than hitting request validation (422) or a missing row (404).
        $journalCount = DB::table('enterprise_finance_journals')->count();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ], false))->assertOk();
            $this->actingAs($actor->fresh())->post(route('admin.erp.finance.journals.store', [], false), [
                'store_id' => $store->id,
                'description' => 'AR cannot forge general ledger entry',
                'idempotency_key' => 'v2-2696-ar-mixed-role-gl-deny',
            ])->assertForbidden();
        }
        $this->assertSame($journalCount, DB::table('enterprise_finance_journals')->count());

        foreach ([
            'finance.gl.read',
            'finance.gl.manage',
            'finance.ap.read',
            'finance.ap.manage',
            'finance.foundation.manage',
            'finance.approvals.manage',
        ] as $capability) {
            $permission = str_ends_with($capability, '.read')
                ? 'finance.view'
                : 'finance.manage';
            $this->assertFalse(
                $access->canPerform($actor, $store->id, $permission, $capability),
                "An AR_OFFICER must not inherit {$capability} via finance.view/manage.",
            );
        }

        // Removing AR entitlements immediately revokes the ability to read the
        // workbench, even if a GL entitlement remains active at the same entity.
        $entitlements->set(FinanceFoundationService::MODULE, $legalEntity, true, [
            'finance.gl.read',
            'finance.gl.manage',
        ]);
        $this->assertFalse($access->canRead($actor, $store->id));
        $this->assertFalse($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));
    }

    public function test_ar_and_super_admin_overlap_cannot_bypass_explicit_denies_or_keep_write_only_ar(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-AR-SUPER-EXPLICIT-DENY',
            'name' => 'AR mixed Super Admin explicit DENY store',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $scope['legal_entity_id']),
            true,
            ['finance.ar.read', 'finance.ar.manage'],
        );

        $access = app(FinanceWorkbenchAccess::class);
        $actor = $actor->fresh();
        $this->assertTrue($access->canRead($actor, $store->id));
        $this->assertTrue($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));
        $journalCount = DB::table('enterprise_finance_journals')->count();

        // The shared User permission helper grants SUPER_ADMIN before looking
        // at overrides. The AR duty boundary must deny the same request.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();
            $this->assertTrue($principal->hasPermission('finance.view', $store->id));
            $this->assertFalse($access->canRead($principal, $store->id));
            $this->assertFalse($access->canReadCapability(
                $principal, $store->id, 'finance.ar.read',
            ));
            $this->assertFalse($access->canPerform(
                $principal, $store->id, 'finance.manage', 'finance.ar.manage',
            ));
        }

        // Removing the store DENY restores the original legal authorization,
        // while a GLOBAL manage DENY blocks only AR drafts, not AR reads.
        DB::table('user_permission_overrides')->where('user_id', $actor->id)->delete();
        $this->assertTrue($access->canRead($actor->fresh(), $store->id));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage', $store->id));
        $this->assertTrue($access->canRead($actor->fresh(), $store->id));
        $this->assertFalse($access->canPerform(
            $actor->fresh(), $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        // A further GLOBAL read DENY revokes the entire AR read/draft duty.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse($access->canRead($actor->fresh(), $store->id));
        $this->assertFalse($access->canPerform(
            $actor->fresh(), $store->id, 'finance.manage', 'finance.ar.manage',
        ));
        $this->assertSame($journalCount, DB::table('enterprise_finance_journals')->count());
    }

    public function test_ar_officer_store_entitlement_overrides_legal_entity_grant_without_finance_writes(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-AR-STORE-ENTITLEMENT',
            'name' => 'Wholesale AR store-local entitlement regression',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $scope['legal_entity_id']),
            true,
            ['finance.ar.read', 'finance.ar.manage'],
        );

        $access = app(FinanceWorkbenchAccess::class);
        $actor = $actor->fresh();
        $journalCount = DB::table('enterprise_finance_journals')->count();

        $this->assertTrue($actor->hasPermission('finance.view'));
        $this->assertTrue($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertTrue($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        // A more specific store DENY must mask a valid global AR role and
        // legal-entity allowance, without revoking that historical role.
        $storeScope = new EntitlementScope(EntitlementScope::STORE, $store->id);
        $entitlements->set(FinanceFoundationService::MODULE, $storeScope, false);
        $this->assertFalse($access->canRead($actor, $store->id));
        $this->assertFalse($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        // An enabled store limited to AR read must never inherit write access
        // from the parent legal entity or the global finance.manage grant.
        $entitlements->set(FinanceFoundationService::MODULE, $storeScope, true, [
            'finance.ar.read',
        ]);
        $this->assertTrue($access->canRead($actor, $store->id));
        $this->assertTrue($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        $entitlements->set(FinanceFoundationService::MODULE, $storeScope, true, [
            'finance.ar.read', 'finance.ar.manage',
        ]);
        $this->assertTrue($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        // Removing a store from service is immediately authoritative too.
        $store->forceFill(['is_active' => false])->save();
        $this->assertFalse($access->canRead($actor, $store->id));
        $this->assertFalse($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));
        $this->assertSame($journalCount, DB::table('enterprise_finance_journals')->count());
    }

    public function test_archived_ar_officer_with_stale_active_flag_loses_finance_read_and_manage_without_erasing_roles(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-AR-ARCHIVED-ACTOR',
            'name' => 'Archived AR duty negative scope',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($store);
        $scope = $hierarchy->scopeForStore($store->id);
        $this->assertNotNull($scope);

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, (int) $scope['legal_entity_id']),
            true,
            ['finance.ar.read', 'finance.ar.manage'],
        );
        $access = app(FinanceWorkbenchAccess::class);
        $this->assertTrue($access->canRead($actor, $store->id));
        $this->assertTrue($access->canReadCapability($actor, $store->id, 'finance.ar.read'));
        $this->assertTrue($access->canPerform(
            $actor, $store->id, 'finance.manage', 'finance.ar.manage',
        ));

        $roleId = Role::query()->where('code', 'AR_OFFICER')->firstOrFail()->id;
        $journalCount = DB::table('enterprise_finance_journals')->count();

        // Offboarding may be interrupted after setting archive status but
        // before deactivation. Roles must survive for audit, never authority.
        $actor->forceFill(['archived_at' => now(), 'is_active' => true])->save();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $archived = $actor->fresh();
            $this->assertTrue((bool) $archived->is_active);
            $this->assertNotNull($archived->archived_at);
            $this->assertFalse($access->canRead($archived, $store->id), $locale);
            $this->assertFalse($access->canReadCapability(
                $archived, $store->id, 'finance.ar.read',
            ), $locale);
            $this->assertFalse($access->canPerform(
                $archived, $store->id, 'finance.manage', 'finance.ar.manage',
            ), $locale);
        }

        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $roleId]);
        $this->assertSame($journalCount, DB::table('enterprise_finance_journals')->count());
    }

    private function officer(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'AR_OFFICER')->firstOrFail());

        return $actor->fresh();
    }

    private function enableFinance(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('finance') === null) {
            $registry->syncManifest('finance');
        }

        if (! $registry->isEnabled('finance')) {
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLED);
        }
    }
}
