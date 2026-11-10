<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Services\PlatformOwnerProvisioner;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P360: Sidebar is a projection of real operational readiness, not a grant.
 * #2839 separately owns the direct-HTTP readiness middleware.
 */
final class V2SidebarOperationalReadinessProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_entitled_but_uncertified_erp_is_hidden_from_staff_in_both_locales(): void
    {
        $operator = $this->superAdmin();
        $this->enableProcurement($operator);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('procurement'));
        $this->assertTrue($operator->hasPermission('procurement.suppliers.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            app()->setLocale($locale);
            $operator->forceFill(['locale' => $locale])->save();

            // Sidebar projection is read-only; ERP Center itself may bootstrap
            // local test modules and emit unrelated lifecycle audit events.
            $beforeNavigation = DB::table('audit_logs')->count();
            $this->assertNull($this->navigationItem($operator->fresh(), 'erp_procurement'));
            $this->assertSame($beforeNavigation, DB::table('audit_logs')->count());
            $this->actingAs($operator->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$dir.'"', false);
        }

    }

    public function test_exact_version_certification_with_healthy_probe_restores_only_the_real_authorized_route(): void
    {
        $operator = $this->superAdmin();
        $this->enableProcurement($operator);
        $version = app(ModuleRegistry::class)->manifest('procurement')->version;

        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->assertNull($this->navigationItem($operator->fresh(), 'erp_procurement'));

        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $operator->forceFill(['locale' => $locale])->save();
            $item = $this->navigationItem($operator->fresh(), 'erp_procurement');
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame([], $item['params']);
        }

        $denied = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertNull($this->navigationItem($denied, 'erp_procurement'));
        $this->actingAs($denied)
            ->get(route('admin.erp.procurement.index'))
            ->assertForbidden();
    }

    public function test_failed_module_health_hides_even_exact_version_certified_sidebar_link(): void
    {
        $operator = $this->superAdmin();
        $this->enableProcurement($operator);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        config()->set('enterprise_module_health.probes.procurement', [
            V2SidebarReadinessFailureProbe::class,
        ]);

        $this->assertNull($this->navigationItem($operator->fresh(), 'erp_procurement'));
    }

    public function test_registered_platform_owner_gets_diagnostics_instead_of_unready_operational_link(): void
    {
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'v2-p360-platform-owner@example.test',
            'StrongUniqueOwnerSecret!2026#P360',
        );
        $this->assertTrue($owner->isPlatformOwner());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $item = $this->navigationItem($owner->fresh(), 'erp_procurement');

            $this->assertNotNull($item);
            $this->assertSame('admin.enterprise-modules.show', $item['route']);
            $this->assertSame(['module' => 'procurement'], $item['params']);
            $this->actingAs($owner->fresh())
                ->get(route('admin.enterprise-modules.show', $item['params']))
                ->assertOk()
                ->assertSee('dir="'.$dir.'"', false);
        }

        // A role assignment without the one-owner registry is not owner authority.
        $forged = User::factory()->create(['is_active' => true]);
        $forged->roles()->attach(Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail());
        $this->assertFalse($forged->isPlatformOwner());
        $this->assertNull($this->navigationItem($forged, 'erp_procurement'));
        $this->actingAs($forged)
            ->get(route('admin.enterprise-modules.show', ['module' => 'procurement']))
            ->assertForbidden();
    }

    /** @return array<string,mixed>|null */
    private function navigationItem(User $user, string $key): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                if (($item['key'] ?? null) === $key) {
                    return $item;
                }
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if (($child['key'] ?? null) === $key) {
                        return $child;
                    }
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

    private function enableProcurement(User $actor): void
    {
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $actor,
        );
    }
}

final class V2SidebarReadinessFailureProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('v2-p360-health-check');
    }
}
