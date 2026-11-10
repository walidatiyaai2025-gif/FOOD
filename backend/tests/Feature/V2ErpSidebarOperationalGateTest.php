<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2ErpSidebarOperationalGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_and_entitled_but_uncertified_module_is_informational_only(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'en', 'erp-nav-uncertified@example.test');
        $this->enableProcurement($actor);

        $this->assertFalse($this->hasNavigationItem($actor, 'erp_procurement'));
        $this->assertTrue($this->hasNavigationItem($actor, 'erp_center'));

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));
        $response->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-erp-key="procurement" data-erp-status="degraded"', false);
    }

    public function test_same_version_certification_and_entitlement_reveal_only_authorized_operational_link(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'ar', 'erp-nav-ready@example.test');
        $this->enableProcurement($actor);
        $this->certifyProcurement();

        $this->assertSame(
            'ready',
            app(ModuleOperationalReadiness::class)->status(
                'procurement',
                app(ModuleRegistry::class)->manifest('procurement')->version,
                app(ModuleRegistry::class)->state('procurement'),
            ),
        );
        $this->assertTrue($this->hasNavigationItem($actor, 'erp_procurement'));

        $item = $this->navigationItem($actor, 'erp_procurement');
        $this->assertSame('admin.erp.procurement.index', $item['route']);
        $this->assertSame('procurement.suppliers.view', $item['permission']);

        $this->actingAs($actor)->get(route('admin.erp.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false);
    }

    public function test_stale_version_certification_fails_closed_without_hiding_diagnostic_center(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'en', 'erp-nav-stale@example.test');
        $this->enableProcurement($actor);
        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);

        $this->assertFalse($this->hasNavigationItem($actor, 'erp_procurement'));
        $this->assertTrue($this->hasNavigationItem($actor, 'erp_center'));
    }

    public function test_explicit_entitlement_deny_never_becomes_a_sidebar_permission(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'en', 'erp-nav-entitlement-deny@example.test');
        $this->enableProcurement($actor);
        $this->certifyProcurement();

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            false,
        );

        $this->assertFalse($this->hasNavigationItem($actor, 'erp_procurement'));
    }

    public function test_unhealthy_probe_blocks_operational_navigation_despite_certification(): void
    {
        $actor = $this->globalUser('SUPER_ADMIN', 'en', 'erp-nav-health-deny@example.test');
        $this->enableProcurement($actor);
        $this->certifyProcurement();
        config()->set('enterprise_module_health.probes.procurement', [V2ErpSidebarFailingProbe::class]);

        $this->assertFalse($this->hasNavigationItem($actor, 'erp_procurement'));
        $this->assertTrue($this->hasNavigationItem($actor, 'erp_center'));
    }

    public function test_lacking_global_role_or_domain_permission_never_receives_procurement_link(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'en', 'erp-nav-setup@example.test');
        $this->enableProcurement($admin);
        $this->certifyProcurement();

        $finance = $this->globalUser('FINANCE', 'en', 'erp-nav-finance@example.test');
        $this->assertFalse($this->hasNavigationItem($finance, 'erp_procurement'));

        $unassigned = User::query()->create([
            'name' => 'Unassigned',
            'email' => 'erp-nav-no-role@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->assertFalse($this->hasNavigationItem($unassigned, 'erp_procurement'));
        $this->assertFalse($this->hasNavigationItem($unassigned, 'erp_center'));

        $this->actingAs($unassigned)->get(route('admin.erp.index'))->assertForbidden();
    }

    private function certifyProcurement(): void
    {
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    private function enableProcurement(User $actor): void
    {
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }

    /** @return array<string,mixed>|null */
    private function navigationItem(User $actor, string $key): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->firstWhere('key', $key);
    }

    private function hasNavigationItem(User $actor, string $key): bool
    {
        return $this->navigationItem($actor, $key) !== null;
    }

    private function globalUser(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}

final class V2ErpSidebarFailingProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('runtime');
    }
}
