<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2EffectiveAccessReviewExportAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_discover_and_export_effective_access_in_both_locales_with_audit(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $subject = $this->userWithRole('CUSTOMER_SERVICE_AGENT', 'en');
        $url = route('admin.security.reports.export', ['report' => 'effective-access']);

        $this->actingAs($admin)
            ->get('/admin/security?section=governance')
            ->assertOk()
            ->assertSee('Effective access / role-scope matrix')
            ->assertSee($url, false)
            ->assertSee('dir="ltr"', false);

        $response = $this->actingAs($admin)->get($url);
        $response->assertOk()->assertDownload();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('user_id,name,email,active,global_roles,store_roles,effective_permissions', $csv);
        $this->assertStringContainsString($subject->email, $csv);
        $this->assertStringContainsString('CUSTOMER_SERVICE_AGENT', $csv);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'security.governance_report.exported',
            'auditable_type' => 'security_report',
            'user_id' => $admin->id,
        ]);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get('/admin/security?section=governance')
            ->assertOk()
            ->assertSee('مصفوفة الوصول الفعلي والأدوار والنطاقات')
            ->assertSee('dir="rtl"', false)
            ->assertSee($url, false);
    }

    public function test_delegated_security_view_cannot_see_or_download_global_effective_access(): void
    {
        $delegate = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = Role::query()->create([
            'code' => 'V2_EFFECTIVE_ACCESS_DELEGATE',
            'name' => 'Effective Access Delegate',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail());
        $delegate->roles()->attach($role);

        $url = route('admin.security.reports.export', ['report' => 'effective-access']);

        $this->actingAs($delegate)
            ->get('/admin/security?section=governance')
            ->assertOk()
            ->assertSee('Governance reports')
            ->assertDontSee($url, false);

        $this->actingAs($delegate)->get($url)->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $delegate->id,
            'event' => 'security.governance_report.exported',
        ]);

        $delegate->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($delegate->fresh())
            ->get('/admin/security?section=governance')
            ->assertOk()
            ->assertSee('تقارير الحوكمة')
            ->assertDontSee($url, false);

        $this->actingAs($delegate->fresh())->get($url)->assertForbidden();
    }

    public function test_roleless_user_cannot_export_global_effective_access(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);

        $this->actingAs($roleless)
            ->get(route('admin.security.reports.export', ['report' => 'effective-access']))
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'security.governance_report.exported',
            'user_id' => $roleless->id,
        ]);
    }

    private function userWithRole(string $roleCode, string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
