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
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 P034: diagnostics describe canonical denial without granting a route.
 */
final class V2ErpDenialDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        // The local Super Admin bootstrap is deliberately disabled in this
        // production-like test: a diagnostic read must not fabricate access.
        $this->app['env'] = 'production';
    }

    public function test_module_lifecycle_denial_explains_setup_and_disabled_states_in_both_languages(): void
    {
        $admin = $this->actor('SUPER_ADMIN');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('setup_required', $card['status']);
            $this->assertSame('module_setup', $card['denial_reason']);
            $this->assertNull($card['url']);
        }

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'disable', $admin);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('disabled', $card['status']);
            $this->assertSame('module_disabled', $card['denial_reason']);
            $this->assertNull($card['url']);

            $this->actingAs($admin->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    public function test_denied_entitlement_and_missing_version_attestation_have_distinct_explanations(): void
    {
        $admin = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('not_entitled', $card['status']);
            $this->assertSame('entitlement_scope', $card['denial_reason']);
            $this->assertNull($card['url']);
        }

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('uncertified', $card['denial_reason']);
            $this->assertNull($card['url']);

            $this->actingAs($admin->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }

        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('available', $card['status']);
            $this->assertNull($card['denial_reason']);
            $this->assertSame(route('admin.erp.procurement.index'), $card['url']);
        }
    }

    public function test_failed_operational_health_is_reported_without_an_operational_link(): void
    {
        $admin = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        config()->set('enterprise_module_health.probes.procurement', [V2P034FailedHealthProbe::class]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $card = $this->viewCard($admin, $locale, $direction);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertNull($card['url']);
        }

        $this->actingAs($admin)->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_unrelated_role_cannot_use_diagnostic_cards_to_discover_forbidden_module(): void
    {
        $finance = $this->actor('FINANCE');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $finance->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($finance->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->assertNull(collect($response->viewData('cards'))->firstWhere('key', 'procurement'));
            $response->assertDontSee('data-erp-key="procurement"', false);
            $this->actingAs($finance->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    /** @return array<string, mixed> */
    private function viewCard(User $admin, string $locale, string $direction): array
    {
        $admin->forceFill(['locale' => $locale])->save();
        app()->setLocale($locale);

        $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index'));
        $response->assertOk()->assertSee('dir="'.$direction.'"', false);

        $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);

        if ($card['denial_reason'] !== null) {
            $response->assertSee(__('admin.erp_center.denial_reasons.'.$card['denial_reason'], [], $locale));
        }

        return $card;
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}

final class V2P034FailedHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('v2-p034-procurement-health');
    }
}
