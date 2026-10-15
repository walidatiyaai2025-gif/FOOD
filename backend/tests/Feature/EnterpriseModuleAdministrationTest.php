<?php

namespace Tests\Feature;

use App\Models\EnterpriseModule;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseModuleAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_admin_sees_business_safe_module_list_and_localized_detail(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'module-owner@example.test');

        $this->actingAs($admin)
            ->get(route('admin.enterprise-modules.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Module Control Plane')
            ->assertSee('Sample Optional')
            ->assertSee('Lifecycle state')
            ->assertSee('Open details');

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.enterprise-modules.show', ['module' => 'sample_optional']))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('إدارة وحدات المنصة')
            ->assertSee('Sample Optional')
            ->assertSee('حالة دورة الحياة');
    }

    public function test_non_platform_admin_is_denied_module_administration(): void
    {
        $finance = $this->userWithRole('FINANCE', 'en', 'module-finance@example.test');

        $this->actingAs($finance)
            ->get(route('admin.enterprise-modules.index'))
            ->assertForbidden();
    }

    public function test_lifecycle_actions_use_core_state_machine_and_record_audit(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'module-lifecycle@example.test');

        $this->actingAs($admin)
            ->post(route('admin.enterprise-modules.lifecycle', ['module' => 'sample_optional']), [
                'action' => 'enable',
            ])
            ->assertRedirect(route('admin.enterprise-modules.show', ['module' => 'sample_optional']));

        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'sample_optional',
            'state' => 'ENABLED',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.module.state_changed',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.enterprise-modules.lifecycle', ['module' => 'sample_optional']), [
                'action' => 'suspend',
            ])
            ->assertRedirect(route('admin.enterprise-modules.show', ['module' => 'sample_optional']));

        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'sample_optional',
            'state' => 'SUSPENDED',
        ]);
    }

    public function test_list_snapshot_uses_bounded_registration_and_entitlement_queries(): void
    {
        config()->set('enterprise_modules.modules.second_optional', [
            'version' => 1,
            'category' => 'operations',
            'default_state' => 'DISABLED',
            'required_core' => ['module_registry'],
            'hard_dependencies' => [],
            'optional_integrations' => [],
            'capabilities' => [],
            'permissions' => [],
            'health_checks' => [],
            'navigation' => [],
        ]);

        EnterpriseModule::query()->create([
            'code' => 'sample_optional',
            'category' => 'platform',
            'manifest_version' => 1,
            'state' => 'DISABLED',
            'manifest' => [],
            'state_changed_at' => now(),
        ]);
        EnterpriseModuleEntitlement::query()->create([
            'module_code' => 'sample_optional',
            'scope_type' => 'platform',
            'scope_id' => 0,
            'scope_key' => 'platform:0',
            'is_enabled' => true,
            'capabilities' => null,
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $modules = app(EnterpriseModuleAdministration::class)->modules();

        $this->assertCount(2, $modules);
        $this->assertSame(
            1,
            collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'from "enterprise_modules"')
                || str_contains($sql, 'from `enterprise_modules`'))->count(),
        );
        $this->assertSame(
            1,
            collect($queries)->filter(static fn (string $sql): bool => str_contains($sql, 'from "enterprise_module_entitlements"')
                || str_contains($sql, 'from `enterprise_module_entitlements`'))->count(),
        );
    }

    private function userWithRole(string $roleCode, string $locale, string $email): User
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
