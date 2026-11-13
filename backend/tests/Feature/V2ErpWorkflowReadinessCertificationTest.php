<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T104R03 / #2840: lifecycle and entitlement are not ERP operational
 * certification. A direct, authenticated route must fail closed even for an
 * otherwise privileged user until the exact manifest is certified.
 */
final class V2ErpWorkflowReadinessCertificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const ROUTES = [
        'procurement' => 'admin.erp.procurement.index',
        'finance' => 'admin.erp.finance.index',
        'treasury' => 'admin.erp.treasury.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_entitled_erp_workflows_still_block_direct_http_until_exact_manifest_certification(): void
    {
        $actor = $this->privilegedActor();
        $this->enableAndEntitle($actor);
        $registry = app(ModuleRegistry::class);
        $readiness = app(ModuleOperationalReadiness::class);
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::ROUTES as $module => $route) {
                $manifestVersion = $registry->manifest($module)->version;
                $this->assertTrue($registry->isEnabled($module));
                $this->assertSame(
                    'uncertified',
                    $readiness->status($module, $manifestVersion, $registry->state($module)),
                );

                $this->actingAs($actor->fresh())->get(route($route))->assertForbidden();

                // A stale approval is not equivalent to approval of the
                // currently installed manifest.
                config()->set('enterprise_module_readiness.certified_versions.'.$module, $manifestVersion + 1);
                $this->actingAs($actor->fresh())->get(route($route))->assertForbidden();
                config()->set('enterprise_module_readiness.certified_versions.'.$module, null);
            }

            // Operational certification may block ERP feature routes, never
            // the existing authorized ERP landing navigation itself.
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_certification_can_be_revoked_without_removing_roles_entitlements_or_audit_history(): void
    {
        $actor = $this->privilegedActor();
        $this->enableAndEntitle($actor);
        $registry = app(ModuleRegistry::class);
        $readiness = app(ModuleOperationalReadiness::class);

        foreach (self::ROUTES as $module => $route) {
            $version = $registry->manifest($module)->version;
            config()->set('enterprise_module_readiness.certified_versions.'.$module, $version);
            $this->assertSame('ready', $readiness->status($module, $version, $registry->state($module)));

            // The same account and entitlement cannot bypass a withdrawn
            // certification just because its module remains ENABLED.
            config()->set('enterprise_module_readiness.certified_versions.'.$module, null);
            $this->assertSame('uncertified', $readiness->status($module, $version, $registry->state($module)));
            $this->actingAs($actor->fresh())->get(route($route))->assertForbidden();

            $this->assertTrue($registry->isEnabled($module));
            $this->assertTrue($actor->fresh()->hasRole('SUPER_ADMIN'));
            $this->assertDatabaseHas('enterprise_module_entitlements', [
                'module_code' => $module,
                'scope_type' => EntitlementScope::PLATFORM,
                'is_enabled' => true,
            ]);
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.entitlement_changed',
        ]);
    }

    private function privilegedActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function enableAndEntitle(User $actor): void
    {
        foreach (array_keys(self::ROUTES) as $module) {
            app(EnterpriseModuleAdministration::class)->perform($module, 'enable', $actor);
            app(ModuleEntitlementManager::class)->set(
                $module,
                EntitlementScope::platform(),
                true,
                null,
                $actor,
            );
        }
    }
}
