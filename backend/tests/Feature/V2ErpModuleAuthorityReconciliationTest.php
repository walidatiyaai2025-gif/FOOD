<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P106: lifecycle ENABLED, entitlement and operational readiness are
 * independent authorities; Sidebar discovery never overrides real HTTP RBAC.
 */
final class V2ErpModuleAuthorityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_and_entitled_procurement_requires_exact_version_readiness_and_revocation_removes_navigation(): void
    {
        $actor = $this->superAdmin();
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);

        $registry = app(ModuleRegistry::class);
        $version = $registry->manifest('procurement')->version;
        $this->assertTrue($registry->isEnabled('procurement'));

        $manager = app(ModuleEntitlementManager::class);
        $scope = EntitlementScope::platform();
        $manager->set('procurement', $scope, true, null, $actor);
        $this->assertTrue(app(ModuleEntitlementResolver::class)->allows('procurement', $scope));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            // A lifecycle switch and a broad entitlement cannot certify ERP.
            config()->set('enterprise_module_readiness.certified_versions', []);
            $this->assertSame('uncertified', app(ModuleOperationalReadiness::class)->status(
                'procurement',
                $version,
                $registry->state('procurement'),
            ));
            $this->assertNull($this->navigationItem($actor->fresh(), 'erp_procurement'));

            // Only explicit attestation of the exact current manifest can
            // expose the route that already has server-side authorization.
            config()->set('enterprise_module_readiness.certified_versions.procurement', $version);
            $this->assertSame('ready', app(ModuleOperationalReadiness::class)->status(
                'procurement',
                $version,
                $registry->state('procurement'),
            ));
            $auditCount = DB::table('audit_logs')->count();
            $item = $this->navigationItem($actor->fresh(), 'erp_procurement');
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());

            // Revoking access must not alter lifecycle or readiness, yet
            // the menu must disappear on the next independent projection.
            $manager->set('procurement', $scope, false, null, $actor);
            $this->assertTrue($registry->isEnabled('procurement'));
            $this->assertFalse(app(ModuleEntitlementResolver::class)->allows('procurement', $scope));
            $this->assertNull($this->navigationItem($actor->fresh(), 'erp_procurement'));

            $manager->set('procurement', $scope, true, null, $actor);
            $this->assertNotNull($this->navigationItem($actor->fresh(), 'erp_procurement'));

            config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
            $this->assertSame('uncertified', app(ModuleOperationalReadiness::class)->status(
                'procurement',
                $version,
                $registry->state('procurement'),
            ));
            $this->assertNull($this->navigationItem($actor->fresh(), 'erp_procurement'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    public function test_entitled_and_certified_module_does_not_grant_route_access_to_an_unprivileged_user(): void
    {
        $actor = $this->superAdmin();
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $actor,
        );
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $denied = User::factory()->create(['is_active' => true, 'locale' => $locale]);

            $this->assertNull($this->navigationItem($denied, 'erp_procurement'));
            $this->actingAs($denied)
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    /** @return array<string, mixed>|null */
    private function navigationItem(User $user, string $key): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                if (($item['key'] ?? null) === $key) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
