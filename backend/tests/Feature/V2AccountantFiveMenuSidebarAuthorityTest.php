<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * #3913 / #2714 (T353R5): an enabled and entitled B2B Accountant can follow
 * every authorized finance persona Sidebar group into actual HTTP handlers.
 * Neither role admission nor Sidebar presence confers a financial approval.
 */
final class V2AccountantFiveMenuSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only ERP workflow evidence; never a live certificate.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_entitled_accountant_five_menu_navigation_matches_authorized_http_in_both_locales(): void
    {
        $wholesale = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $hierarchy = app(OrganizationHierarchyService::class);
        $hierarchy->ensureDefaultScopeForStore($wholesale);
        $scope = $hierarchy->scopeForStore($wholesale->id);
        $this->assertNotNull($scope);

        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'ACCOUNTANT')->firstOrFail());

        foreach (['finance', 'treasury'] as $module) {
            $registry = app(ModuleRegistry::class);
            if ($registry->registration($module) === null) {
                $registry->syncManifest($module);
            }
            app(ModuleLifecycleManager::class)->transition($module, ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition($module, ModuleState::ENABLED);
            config()->set(
                "enterprise_module_readiness.certified_versions.{$module}",
                $registry->manifest($module)->version,
            );
            $this->assertSame('ready', app(ModuleOperationalReadiness::class)->status(
                $module,
                $registry->manifest($module)->version,
                ModuleState::ENABLED,
            ));
        }

        $entitlements = app(ModuleEntitlementManager::class);
        $entity = new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']);
        $entitlements->set('finance', $entity, true, [
            'finance.foundation.read',
            'finance.gl.read',
            'finance.ar.read',
            'finance.ap.read',
        ], $actor);
        $entitlements->set('treasury', $entity, true, [
            'treasury.receipts.read',
        ], $actor);

        $this->assertContains('ACCOUNTANT', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('ACCOUNTANT', config('admin.channels.b2c.global_roles'));

        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-3913-FOREIGN-RETAIL',
            'name' => 'P3913 foreign Retail scope',
            'is_active' => true,
        ]);
        $hierarchy->ensureDefaultScopeForStore($retail);

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'entries' => DB::table('enterprise_finance_ledger_entries')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();
            $this->assertTrue($principal->hasPermission('finance.view', $wholesale->id));
            $this->assertTrue($principal->hasPermission('finance.post', $wholesale->id));
            $this->assertTrue($principal->hasPermission('reports.view'));
            $this->assertFalse($principal->hasPermission('finance.approve'));
            $this->assertFalse($principal->hasPermission('reports.export'));
            $this->assertFalse($principal->hasPermission('finance.view', $retail->id));

            app()->forgetInstance(AdminNavigation::class);
            $nav = app(AdminNavigation::class);
            $this->assertTrue($nav->canAccess($principal, 'b2b'));
            $this->assertFalse($nav->canAccess($principal, 'b2c'));
            $groups = collect($nav->groupsFor($principal))->keyBy('key');

            foreach ([
                'overview' => 'enterprise_action_center',
                'finance' => 'erp_finance',
                'receivables' => 'erp_receivables',
                'treasury' => 'treasury_master',
                'analytics' => 'reports_center',
            ] as $group => $itemKey) {
                $this->assertTrue($groups->has($group), "{$locale}: missing {$group} group");
                $items = collect($groups->get($group)['children'])->keyBy('key');
                $this->assertTrue($items->has($itemKey), "{$locale}: missing {$itemKey}");
                $item = $items->get($itemKey);

                $this->assertNotEmpty($item['label'], "{$locale}: empty {$itemKey} label");
                $this->assertTrue(Route::has($item['route']), "{$locale}: unregistered {$itemKey} route");
                $response = $this->actingAs($principal)
                    ->get(route($item['route'], $item['params'], false));

                $this->assertContains(
                    $response->getStatusCode(),
                    [200, 302],
                    "{$locale}: {$itemKey} links to HTTP {$response->getStatusCode()}",
                );

                if ($itemKey === 'erp_finance') {
                    $response->assertOk()
                        ->assertSee('data-finance-workbench="true"', false)
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }

            $financeUrl = route('admin.erp.finance.index', [
                'store_id' => $wholesale->id,
                'tab' => 'receivables',
            ], false);
            $this->actingAs($principal)->get($financeUrl)->assertOk();

            // Wholesale Finance authority cannot be transplanted into Retail.
            $this->actingAs($principal)
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $retail->id,
                    'tab' => 'receivables',
                ], false))
                ->assertNotFound();

            $keys = $groups->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key');
            foreach (['sidebar_role_access', 'sidebar_layout', 'retail_store_provisioning'] as $forbidden) {
                $this->assertFalse($keys->contains($forbidden), "{$locale}: leaked {$forbidden}");
            }
        }

        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->id]);
        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['entries'], DB::table('enterprise_finance_ledger_entries')->count());
        $this->assertSame($before['receipts'], DB::table('enterprise_treasury_receipts')->count());
    }
}
