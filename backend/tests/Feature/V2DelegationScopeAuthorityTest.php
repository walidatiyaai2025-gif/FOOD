<?php

namespace Tests\Feature;

use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2DelegationScopeAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_delegations_are_scoped_and_foreign_direct_mutations_are_forbidden(): void
    {
        $organizationA = EnterpriseOrganization::query()->firstOrFail();
        $organizationB = EnterpriseOrganization::query()->create([
            'code' => 'P342_OTHER_ORG',
            'name' => 'P342 foreign organization',
            'is_active' => true,
        ]);

        $viewerRole = $this->roleWithPermissions('P342_VIEWER', ['security.view']);
        $managerRole = $this->roleWithPermissions('P342_MANAGER', [
            'security.delegations.manage',
            'orders.approve',
        ]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach([$viewerRole->id, $managerRole->id]);

        foreach ([$viewerRole, $managerRole] as $role) {
            EnterpriseRoleDataScope::query()->create([
                'role_id' => $role->id,
                'scope_type' => 'organization',
                'scope_id' => $organizationA->id,
                'scope_key' => 'organization:'.$organizationA->id,
                'is_active' => true,
            ]);
        }

        $delegate = User::factory()->create(['is_active' => true]);
        $otherDelegator = User::factory()->create(['is_active' => true]);

        $own = $this->delegation($actor, $delegate, $organizationA->id, '2030-03-04 11:11:00');
        $foreignScope = $this->delegation($otherDelegator, $delegate, $organizationB->id, '2031-05-06 13:37:00');
        $foreignOwner = $this->delegation($otherDelegator, $delegate, $organizationA->id, '2032-07-08 14:41:00');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.enterprise-security.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('2030-03-04 11:11')
                ->assertSee('2032-07-08 14:41')
                ->assertDontSee('2031-05-06 13:37')
                ->assertDontSee('P342 foreign organization');

            $this->actingAs($actor->fresh())
                ->post(route('admin.enterprise-security.delegations.store'), [
                    'delegate_id' => $delegate->id,
                    'permission_code' => 'orders.approve',
                    'scope_key' => 'organization:'.$organizationB->id,
                    'starts_at' => now()->subMinute()->toIso8601String(),
                    'ends_at' => now()->addHour()->toIso8601String(),
                ])->assertForbidden();

            $this->actingAs($actor->fresh())
                ->delete(route('admin.enterprise-security.delegations.destroy', $foreignScope))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->delete(route('admin.enterprise-security.delegations.destroy', $foreignOwner))
                ->assertForbidden();
        }

        $this->assertSame(0, EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')->count());
        $this->assertDatabaseHas('enterprise_delegations', [
            'id' => $foreignScope->id,
            'revoked_at' => null,
        ]);

        $actor->forceFill(['locale' => 'en'])->save();
        $this->actingAs($actor->fresh())
            ->delete(route('admin.enterprise-security.delegations.destroy', $own))
            ->assertRedirect()->assertSessionHas('status');

        $change = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->firstOrFail();

        $this->assertDatabaseHas('enterprise_delegations', ['id' => $own->id, 'revoked_at' => null]);

        $this->actingAs($actor->fresh())
            ->post(route('admin.enterprise-security.changes.decide', $change), [
                'decision' => 'APPROVED',
            ])->assertForbidden();

        $checker = $this->superAdmin();
        $this->actingAs($checker)
            ->post(route('admin.enterprise-security.changes.decide', $change), [
                'decision' => 'APPROVED',
            ])->assertRedirect();

        $this->assertNotNull($own->fresh()->revoked_at);
        $this->assertNull($foreignScope->fresh()->revoked_at);
        $this->assertNull($foreignOwner->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.security.change_applied']);
    }

    public function test_valid_delegation_creation_requires_independent_checker_and_enforces_time_order(): void
    {
        $managerRole = $this->roleWithPermissions('P342_CREATE', [
            'security.view',
            'security.delegations.manage',
            'orders.approve',
        ]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($managerRole->id);
        $this->actingAs($actor)->get(route('admin.enterprise-security.index'))->assertOk();
        $delegate = User::factory()->create(['is_active' => true]);

        $invalid = [
            'delegate_id' => $delegate->id,
            'permission_code' => 'orders.approve',
            'scope_key' => 'platform:*',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->subMinute()->toIso8601String(),
        ];
        $this->actingAs($actor)
            ->post(route('admin.enterprise-security.delegations.store'), $invalid)
            ->assertRedirect()->assertSessionHasErrors('ends_at');

        $valid = [
            ...$invalid,
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ];
        $this->actingAs($actor)
            ->post(route('admin.enterprise-security.delegations.store'), $valid)
            ->assertRedirect()->assertSessionHas('status');

        $change = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->firstOrFail();

        $this->assertDatabaseCount('enterprise_delegations', 0);
        $this->actingAs($this->superAdmin())
            ->post(route('admin.enterprise-security.changes.decide', $change), [
                'decision' => 'APPROVED',
            ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_delegations', [
            'delegator_id' => $actor->id,
            'delegate_id' => $delegate->id,
            'permission_code' => 'orders.approve',
            'scope_key' => 'platform:*',
            'revoked_at' => null,
        ]);
    }

    public function test_roleless_actor_cannot_read_or_mutate_delegations(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $this->actingAs($actor)->get(route('admin.enterprise-security.index'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.enterprise-security.delegations.store'), [
            'delegate_id' => $actor->id,
            'permission_code' => 'orders.approve',
            'scope_key' => 'platform:*',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertForbidden();
    }

    private function delegation(User $delegator, User $delegate, int $organizationId, string $startsAt): EnterpriseDelegation
    {
        return EnterpriseDelegation::query()->create([
            'delegator_id' => $delegator->id,
            'delegate_id' => $delegate->id,
            'permission_code' => 'orders.approve',
            'scope_type' => 'organization',
            'scope_id' => $organizationId,
            'scope_key' => 'organization:'.$organizationId,
            'starts_at' => $startsAt,
            'ends_at' => '2035-01-01 12:00:00',
        ]);
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
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
        $ids = Permission::query()->whereIn('code', $permissions)->pluck('id')->all();
        $this->assertCount(count($permissions), $ids);
        $role->permissions()->sync($ids);

        return $role->refresh();
    }
}
