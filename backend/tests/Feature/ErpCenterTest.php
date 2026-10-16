<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ErpCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_module_is_explicit_and_never_exposes_an_operational_cta(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-owner@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('ERP Center')
            ->assertSee('data-erp-key="procurement" data-erp-status="disabled"', false)
            ->assertSee('Disabled')
            ->assertSee('dir="ltr"', false);
    }

    public function test_enabled_and_entitled_module_exposes_only_its_canonical_destination(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-enabled@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['module' => 'procurement']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertSee(route('admin.action-center.index', ['module' => 'procurement']), false);

        $erpGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'erp');
        $this->assertNotNull($erpGroup);
        $this->assertContains('erp_procurement', array_column($erpGroup['children'], 'key'));
    }

    public function test_finance_actor_does_not_receive_procurement_or_wms_actions(): void
    {
        $actor = $this->userWithGlobalRole('FINANCE', 'ar', 'erp-finance@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('مركز ERP')
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('data-erp-key="procurement"', false)
            ->assertDontSee('data-erp-key="wms"', false);
    }

    private function userWithGlobalRole(string $roleCode, string $locale, string $email): User
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
