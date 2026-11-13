<?php

namespace Tests\Feature;

use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2ApprovalLimitsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_approval_limit_read_and_direct_mutations_are_scoped_with_independent_checker(): void
    {
        $organizationA = EnterpriseOrganization::query()->firstOrFail();
        $organizationB = EnterpriseOrganization::query()->create([
            'code' => 'P341_SECOND_ORG',
            'name' => 'P341 other organization',
            'is_active' => true,
        ]);

        $readerRole = $this->roleWithPermissions('P341_READER', ['security.view']);
        $managerRole = $this->roleWithPermissions('P341_MANAGER', [
            'security.approval_limits.manage',
            'orders.approve',
        ]);
        $approverRole = $this->roleWithPermissions('P341_APPROVER', ['orders.approve']);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach([$readerRole->id, $managerRole->id]);

        foreach ([$readerRole, $managerRole] as $role) {
            EnterpriseRoleDataScope::query()->create([
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => $organizationA->id,
                'scope_key' => 'organization:'.$organizationA->id,
                'is_active' => true,
            ]);
        }

        $ownLimit = EnterpriseApprovalLimit::query()->create([
            'role_id' => $approverRole->id,
            'permission_code' => 'orders.approve',
            'scope_type' => 'organization',
            'scope_id' => $organizationA->id,
            'scope_key' => 'organization:'.$organizationA->id,
            'currency' => 'KWD',
            'max_amount' => '123.000',
            'is_active' => true,
        ]);
        $foreignLimit = EnterpriseApprovalLimit::query()->create([
            'role_id' => $approverRole->id,
            'permission_code' => 'orders.approve',
            'scope_type' => 'organization',
            'scope_id' => $organizationB->id,
            'scope_key' => 'organization:'.$organizationB->id,
            'currency' => 'KWD',
            'max_amount' => '987.000',
            'is_active' => true,
        ]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get(route('admin.enterprise-security.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('123.000')
                ->assertDontSee('987.000')
                ->assertDontSee('P341 other organization');

            $this->actingAs($actor->fresh())
                ->post(route('admin.enterprise-security.approval-limits.store'), [
                    'role_id' => $approverRole->id,
                    'permission_code' => 'orders.approve',
                    'scope_key' => 'organization:'.$organizationB->id,
                    'currency' => 'KWD',
                    'max_amount' => '900.000',
                ])->assertForbidden();

            $this->actingAs($actor->fresh())
                ->delete(route('admin.enterprise-security.approval-limits.destroy', $foreignLimit))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('enterprise_approval_limits', [
            'id' => $foreignLimit->id,
            'max_amount' => '987.000',
            'is_active' => true,
        ]);
        $this->assertSame(0, EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')->count());

        $actor->forceFill(['locale' => 'en'])->save();
        $this->actingAs($actor->fresh())
            ->post(route('admin.enterprise-security.approval-limits.store'), [
                'role_id' => $approverRole->id,
                'permission_code' => 'orders.approve',
                'scope_key' => 'organization:'.$organizationA->id,
                'currency' => 'KWD',
                'max_amount' => '125.000',
            ])->assertRedirect()->assertSessionHas('status');

        $change = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->firstOrFail();
        $this->assertDatabaseHas('enterprise_approval_limits', [
            'id' => $ownLimit->id,
            'max_amount' => '123.000',
        ]);

        $this->actingAs($actor->fresh())
            ->post(route('admin.enterprise-security.changes.decide', $change), [
                'decision' => 'APPROVED',
            ])->assertForbidden();

        $checker = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $checker->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAs($checker)
            ->post(route('admin.enterprise-security.changes.decide', $change), [
                'decision' => 'APPROVED',
            ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_approval_limits', [
            'id' => $ownLimit->id,
            'max_amount' => '125.000',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('enterprise_approval_limits', [
            'id' => $foreignLimit->id,
            'max_amount' => '987.000',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.security.change_applied',
        ]);
    }

    public function test_roleless_actor_cannot_read_or_mutate_approval_limits(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);

        $this->actingAs($actor)->get(route('admin.enterprise-security.index'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.enterprise-security.approval-limits.store'), [
            'role_id' => 1,
            'permission_code' => 'orders.approve',
            'scope_key' => 'platform:*',
            'currency' => 'KWD',
            'max_amount' => '1.000',
        ])->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()->whereIn('code', $permissions)->pluck('id')->all();
        $this->assertCount(count($permissions), $permissionIds);
        $role->permissions()->sync($permissionIds);

        return $role->refresh();
    }
}
