<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * #3890 / T353R5: two ENABLED modules are not an approved B2B channel,
 * a Finance/Treasury entitlement or a safe cross-domain GL grant.
 */
final class V2TreasuryOfficerDualEnabledAuthorizationTest extends TestCase
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

    public function test_enabled_treasury_officer_cannot_enter_erp_or_other_tenant_without_channel_and_entitlements(): void
    {
        $registry = app(ModuleRegistry::class);

        // Prove a live module lifecycle, not the trivial disabled-module case
        // already covered by #3863.
        foreach (['treasury', 'finance'] as $module) {
            if ($registry->registration($module) === null) {
                $registry->syncManifest($module);
            }
            app(ModuleLifecycleManager::class)->transition($module, ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition($module, ModuleState::ENABLED);
            config()->set(
                "enterprise_module_readiness.certified_versions.{$module}",
                $registry->manifest($module)->version,
            );
            $this->assertTrue($registry->isEnabled($module));
            $this->assertSame('ready', app(ModuleOperationalReadiness::class)->status(
                $module,
                $registry->manifest($module)->version,
                ModuleState::ENABLED,
            ));
        }

        $wholesale = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($wholesale);
        $retail = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-3890-RETAIL',
            'name' => 'V2-3890 unapproved Treasury Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $entitlements = app(ModuleEntitlementResolver::class);
        foreach (['treasury', 'finance'] as $module) {
            foreach ([$wholesale->id, $retail->id] as $storeId) {
                $this->assertFalse($entitlements->allows(
                    $module,
                    new EntitlementScope(EntitlementScope::STORE, $storeId),
                ));
            }
        }

        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('TREASURY_OFFICER');
        $this->assertIsArray($definition);
        $this->assertSame(['treasury'], $definition['required_modules']);
        $this->assertContains('finance', $definition['optional_modules']);
        $this->assertTrue($catalog->isAvailable('TREASURY_OFFICER'));
        $this->assertNotContains('TREASURY_OFFICER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('TREASURY_OFFICER', config('admin.channels.b2c.global_roles'));

        $role = Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.manage', 'reports.view'],
            $role->permissions()->pluck('code')->all(),
        );
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach($role);

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'entries' => DB::table('enterprise_finance_ledger_entries')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();
            $this->actingAs($principal);

            // Permissions may be persisted and effective once Treasury is
            // enabled; separate channel/entitlement gates remain mandatory.
            foreach (['finance.view', 'finance.manage', 'reports.view'] as $permission) {
                $this->assertTrue($principal->hasPermission($permission));
            }
            foreach (['finance.post', 'finance.approve', 'security.approve'] as $forbidden) {
                $this->assertFalse($principal->hasPermission($forbidden));
            }
            $this->assertFalse($principal->hasPermission('finance.view', $retail->id));

            app()->forgetInstance(AdminNavigation::class);
            $navigation = app(AdminNavigation::class);
            $this->assertFalse($navigation->canAccess($principal, 'b2b'));
            $this->assertFalse($navigation->canAccess($principal, 'b2c'));

            $keys = collect($navigation->groupsFor($principal))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key');
            foreach (['erp_treasury', 'erp_finance', 'erp_receivables', 'erp_payables'] as $forbiddenKey) {
                $this->assertFalse(
                    $keys->contains($forbiddenKey),
                    "TREASURY_OFFICER / {$locale} leaked {$forbiddenKey} without channel or entitlement.",
                );
            }

            foreach ([
                ['admin.erp.treasury.index', ['store_id' => $wholesale->id]],
                ['admin.erp.treasury.index', ['store_id' => $retail->id]],
                ['admin.erp.finance.index', ['store_id' => $wholesale->id, 'tab' => 'accounts']],
                ['admin.erp.finance.index', ['store_id' => $retail->id, 'tab' => 'accounts']],
            ] as [$routeName, $parameters]) {
                $this->assertTrue(Route::has($routeName), "Missing canonical route {$routeName}");
                $response = $this->get(route($routeName, $parameters, false));
                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 404],
                    "TREASURY_OFFICER / {$locale} / {$routeName} leaked HTTP {$response->getStatusCode()}",
                );
            }
        }

        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $role->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->id]);
        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['entries'], DB::table('enterprise_finance_ledger_entries')->count());
        $this->assertSame($before['receipts'], DB::table('enterprise_treasury_receipts')->count());
    }
}
