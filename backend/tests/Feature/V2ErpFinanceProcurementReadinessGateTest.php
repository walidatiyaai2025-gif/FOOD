<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P377: a finance or procurement Sidebar link must never be mistaken for
 * operational certification or an independent RBAC/tenant entitlement grant.
 */
final class V2ErpFinanceProcurementReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    private const MODULES = [
        'procurement' => [
            'key' => 'erp_procurement',
            'group' => 'catalog',
            'route' => 'admin.erp.procurement.index',
        ],
        'finance' => [
            'key' => 'erp_finance',
            'group' => 'finance',
            'route' => 'admin.erp.finance.index',
        ],
        'treasury' => [
            'key' => 'erp_treasury',
            'group' => 'finance',
            'route' => 'admin.erp.treasury.index',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_and_entitled_financial_and_procurement_modules_stay_hidden_until_exact_version_certification(): void
    {
        $actor = $this->superAdmin();
        $this->enableAndEntitle($actor);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::MODULES as $code => $definition) {
                $registry = app(ModuleRegistry::class);
                $version = $registry->manifest($code)->version;

                $this->assertTrue($registry->isEnabled($code));
                $this->assertSame('uncertified', app(ModuleOperationalReadiness::class)->status(
                    $code,
                    $version,
                    $registry->state($code),
                ));

                $auditBeforeNavigation = DB::table('audit_logs')->count();
                $this->assertNull($this->navigationItem($actor->fresh(), $definition['key']));
                $this->assertSame($auditBeforeNavigation, DB::table('audit_logs')->count());

                // Attesting a different manifest version must never unlock
                // financial posting or procurement workspaces.
                config()->set('enterprise_module_readiness.certified_versions.'.$code, $version + 1);
                $this->assertNull($this->navigationItem($actor->fresh(), $definition['key']));
                config()->set('enterprise_module_readiness.certified_versions.'.$code, null);
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_only_approved_versions_project_real_routes_without_bypassing_entitlements_or_tenant_roles(): void
    {
        $actor = $this->superAdmin();
        $this->enableAndEntitle($actor);

        foreach (array_keys(self::MODULES) as $code) {
            config()->set(
                'enterprise_module_readiness.certified_versions.'.$code,
                app(ModuleRegistry::class)->manifest($code)->version,
            );
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::MODULES as $definition) {
                $item = $this->navigationItem($actor->fresh(), $definition['key']);
                $this->assertNotNull($item, $locale.' '.$definition['key']);
                $this->assertSame($definition['group'], $item['group']);
                $this->assertSame($definition['route'], $item['route']);
                $this->assertTrue(Route::has($item['route']));
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        // An approved module is still inaccessible after entitlement revocation.
        app(ModuleEntitlementManager::class)->set(
            'finance',
            EntitlementScope::platform(),
            false,
            null,
            $actor,
        );
        $this->assertNull($this->navigationItem($actor->fresh(), 'erp_finance'));
        $this->assertNotNull($this->navigationItem($actor->fresh(), 'erp_procurement'));

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $retail = $this->retailStoreAdmin();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$roleless, $retail] as $denied) {
                $denied->forceFill(['locale' => $locale])->save();

                foreach (self::MODULES as $definition) {
                    $this->assertNull($this->navigationItem($denied->fresh(), $definition['key']));
                    $this->actingAs($denied->fresh())
                        ->get(route($definition['route']))
                        ->assertForbidden();
                }
            }
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    /** @return array{group:string,route:string}|null */
    private function navigationItem(User $user, string $key): ?array
    {
        // An independent navigation resolver prevents cross-actor request memo
        // values from masking a changed entitlement or role in this regression.
        app()->forgetInstance(AdminNavigation::class);

        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === $key) {
                    return [
                        'group' => $group['key'],
                        'route' => $item['route'],
                    ];
                }
            }
        }

        return null;
    }

    private function enableAndEntitle(User $actor): void
    {
        foreach (array_keys(self::MODULES) as $code) {
            app(EnterpriseModuleAdministration::class)->perform($code, 'enable', $actor);
            app(ModuleEntitlementManager::class)->set(
                $code,
                EntitlementScope::platform(),
                true,
                null,
                $actor,
            );
        }
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailStoreAdmin(): User
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-P377-RETAIL',
            'name' => 'P377 Retail Fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
