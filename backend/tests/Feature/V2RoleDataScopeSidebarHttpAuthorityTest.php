<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowStatus;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2RoleDataScopeSidebarHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_role_data_scope_workbench_is_bilingual_and_uses_existing_form(): void
    {
        $admin = $this->superAdmin('en');

        $this->actingAs($admin)
            ->get(route('admin.enterprise-security.index'))
            ->assertOk()
            ->assertSee('Role data scopes')
            ->assertSee('data-enterprise-security-scope-form', false)
            ->assertSee('dir="ltr"', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.enterprise-security.index'))
            ->assertOk()
            ->assertSee('نطاقات بيانات الأدوار')
            ->assertSee('data-enterprise-security-scope-form', false)
            ->assertSee('dir="rtl"', false);
    }

    public function test_http_scope_grant_remains_pending_until_independent_checker_approves(): void
    {
        $maker = $this->superAdmin();
        $checker = $this->superAdmin();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $organization = EnterpriseOrganization::query()->firstOrFail();
        $scopeKey = 'organization:'.$organization->id;
        $url = route('admin.enterprise-security.role-scopes.store');

        $this->actingAs($maker)
            ->from(route('admin.enterprise-security.index'))
            ->post($url, ['role_id' => $role->id, 'scope_key' => $scopeKey])
            ->assertRedirect(route('admin.enterprise-security.index'))
            ->assertSessionHasNoErrors();

        $change = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(WorkflowStatus::PENDING, $change->status);
        $this->assertDatabaseMissing('enterprise_role_data_scopes', [
            'role_id' => $role->id,
            'scope_key' => $scopeKey,
        ]);

        $decision = route('admin.enterprise-security.changes.decide', ['workflowRequest' => $change->id]);

        $this->actingAs($maker)
            ->post($decision, ['decision' => 'APPROVED'])
            ->assertForbidden();

        $this->actingAs($checker)
            ->from(route('admin.enterprise-security.index'))
            ->post($decision, ['decision' => 'APPROVED'])
            ->assertRedirect(route('admin.enterprise-security.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enterprise_role_data_scopes', [
            'role_id' => $role->id,
            'scope_key' => $scopeKey,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.security.change_applied',
            'user_id' => $checker->id,
        ]);
    }

    public function test_read_only_security_viewer_cannot_mutate_scopes_through_direct_http(): void
    {
        $viewerRole = $this->customRole('V2_SCOPE_VIEWER', ['security.view']);
        $viewer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $viewer->roles()->attach($viewerRole);
        $organization = EnterpriseOrganization::query()->firstOrFail();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($viewer)
            ->get(route('admin.enterprise-security.index'))
            ->assertOk()
            ->assertDontSee('data-enterprise-security-scope-form', false);

        $this->actingAs($viewer)
            ->post(route('admin.enterprise-security.role-scopes.store'), [
                'role_id' => $role->id,
                'scope_key' => 'organization:'.$organization->id,
            ])
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.enterprise-security.index'))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->post(route('admin.enterprise-security.role-scopes.store'), [
                'role_id' => $role->id,
                'scope_key' => 'organization:'.$organization->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_workflow_requests', 0);
        $this->assertDatabaseCount('enterprise_role_data_scopes', 0);
    }

    public function test_scoped_maker_cannot_request_foreign_organization_grant(): void
    {
        $organizationA = EnterpriseOrganization::query()->firstOrFail();
        $organizationB = EnterpriseOrganization::query()->create([
            'code' => 'v2_scope_foreign_organization',
            'name' => 'Foreign V2 Organization',
            'is_active' => true,
        ]);

        $makerRole = $this->customRole('V2_SCOPED_SECURITY_MAKER', [
            'security.view',
            'security.scopes.manage',
        ]);
        EnterpriseRoleDataScope::query()->create([
            'role_id' => $makerRole->id,
            'scope_type' => 'organization',
            'scope_id' => $organizationA->id,
            'scope_key' => 'organization:'.$organizationA->id,
            'is_active' => true,
        ]);

        $maker = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $maker->roles()->attach($makerRole);
        $targetRole = $this->customRole('V2_SCOPE_TARGET', ['security.view']);
        $form = route('admin.enterprise-security.index');
        $url = route('admin.enterprise-security.role-scopes.store');

        $this->actingAs($maker)
            ->from($form)
            ->post($url, [
                'role_id' => $targetRole->id,
                'scope_key' => 'organization:'.$organizationB->id,
            ])
            ->assertRedirect($form)
            ->assertSessionHasErrors('scope_key');

        $this->assertDatabaseCount('enterprise_workflow_requests', 0);
        $this->assertDatabaseMissing('enterprise_role_data_scopes', [
            'role_id' => $targetRole->id,
            'scope_key' => 'organization:'.$organizationB->id,
        ]);

        $this->actingAs($maker)
            ->from($form)
            ->post($url, [
                'role_id' => $targetRole->id,
                'scope_key' => 'organization:'.$organizationA->id,
            ])
            ->assertRedirect($form)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enterprise_workflow_requests', [
            'requester_id' => $maker->id,
            'workflow_key' => 'enterprise_security_privileged_change',
        ]);
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    /** @param list<string> $permissions */
    private function customRole(string $code, array $permissions): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => str_replace('_', ' ', $code),
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
        );

        return $role;
    }
}
