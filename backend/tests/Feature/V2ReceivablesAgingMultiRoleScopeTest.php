<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P288/#3894: a second store-scoped role is neither a blanket Retail grant
 * nor an escape hatch from an explicit deny on a different assigned store.
 */
final class V2ReceivablesAgingMultiRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only ERP workflow evidence; never a live certificate.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_two_scoped_retail_roles_do_not_bypass_store_or_explicit_ar_denial_in_either_locale(): void
    {
        $storeA = $this->retailStore('P288-MULTI-A');
        $storeB = $this->retailStore('P288-MULTI-B');
        $foreign = $this->retailStore('P288-MULTI-UNASSIGNED');

        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->grantPlatformErpAccess($actor);
        $financeView = Permission::query()->where('code', 'finance.view')->firstOrFail();

        foreach ([
            [$storeA, 'B2C_STORE_ADMIN'],
            [$storeB, 'RETAIL_FINANCE'],
        ] as [$store, $roleCode]) {
            $role = Role::query()
                ->where('code', $roleCode)
                ->where('scope', 'store')
                ->where('is_active', true)
                ->firstOrFail();
            // CoreReferenceSeeder creates the role identities; the feature
            // fixture grants the same scoped read permission as P288/#3085.
            $role->permissions()->syncWithoutDetaching([$financeView->id]);
            $this->assertTrue($role->permissions()->where('code', 'finance.view')->exists());

            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resolver = app(TenantContextResolver::class);
        $context = $resolver->retail($actor->fresh(), $storeA->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        // All three stores deliberately share the same default legal entity:
        // only actual store-role assignments can distinguish permitted access.
        foreach ([$storeB, $foreign] as $store) {
            $otherScope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
            $this->assertNotNull($otherScope);
            $this->assertSame($scope->legalEntityId, $otherScope['legal_entity_id']);
        }

        $module = FinanceFoundationService::MODULE;
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition($module, ModuleState::ENABLING, $actor);
        $lifecycle->transition($module, ModuleState::ENABLED, $actor);

        $registry = app(ModuleRegistry::class);
        $this->assertSame('ready', app(ModuleOperationalReadiness::class)->status(
            $module,
            $registry->manifest($module)->version,
            ModuleState::ENABLED,
        ));

        app(ModuleEntitlementManager::class)->set(
            $module,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            ['finance.foundation.read', 'finance.ar.read'],
            $actor,
        );

        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $storeA->id));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $storeB->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $foreign->id));

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'entries' => DB::table('enterprise_finance_ledger_entries')->count(),
            'documents' => DB::table('enterprise_ar_documents')->count(),
        ];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();

            foreach ([$storeA, $storeB] as $store) {
                $this->actingAs($principal)
                    ->get(route('admin.erp.finance.index', [
                        'store_id' => $store->id,
                        'tab' => 'receivables',
                    ]))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('finance_workbench.dashboard.aging'));
            }

            $this->actingAs($principal)
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $foreign->id,
                    'tab' => 'receivables',
                ]))
                ->assertNotFound();
        }

        $permission = $financeView;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$storeA->id,
            'store_id' => $storeA->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();

            // Assigned-but-denied Store A must be 403, even when another
            // independently authorized Retail store remains accessible.
            $this->actingAs($principal)
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $storeA->id,
                    'tab' => 'receivables',
                ]))
                ->assertForbidden();

            $this->actingAs($principal)
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $storeB->id,
                    'tab' => 'receivables',
                ]))
                ->assertOk();
        }

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'store_id' => $storeA->id,
            'effect' => 'deny',
        ]);
        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['entries'], DB::table('enterprise_finance_ledger_entries')->count());
        $this->assertSame($before['documents'], DB::table('enterprise_ar_documents')->count());
    }

    private function retailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
