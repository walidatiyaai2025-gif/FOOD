<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStatus;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseSecurityAccessResolver;
use App\Services\EnterpriseSecurityManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseSecurityAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enterprise_security_page_is_authorized_and_localized(): void
    {
        $admin = $this->superAdmin('en');

        $this->actingAs($admin)
            ->get(route('admin.enterprise-security.index'))
            ->assertOk()
            ->assertSee('Enterprise Access &amp; Delegations', false)
            ->assertSee('Maker/checker protected');

        $unauthorized = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($unauthorized)
            ->get(route('admin.enterprise-security.index'))
            ->assertForbidden();

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.enterprise-security.index'))
            ->assertOk()
            ->assertSee('صلاحيات المؤسسة والتفويضات');
    }

    public function test_privileged_role_scope_change_requires_independent_checker_and_applies_once(): void
    {
        $maker = $this->superAdmin();
        $checker = $this->superAdmin();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $change = app(EnterpriseSecurityManager::class)->requestRoleScopeGrant(
            $maker,
            $role,
            'platform',
            null,
        );

        $this->assertSame(WorkflowStatus::PENDING, $change->status);
        $this->assertDatabaseMissing('enterprise_role_data_scopes', [
            'role_id' => $role->id,
            'scope_key' => 'platform:*',
        ]);

        try {
            app(EnterpriseSecurityManager::class)->decideAndApply(
                $change->id,
                $maker,
                'APPROVED',
            );
            self::fail('Maker unexpectedly approved their own privileged security change.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $approved = app(EnterpriseSecurityManager::class)->decideAndApply(
            $change->id,
            $checker,
            'APPROVED',
        );
        $this->assertSame(WorkflowStatus::APPROVED, $approved->status);

        app(EnterpriseSecurityManager::class)->decideAndApply(
            $change->id,
            $checker,
            'APPROVED',
        );

        $this->assertDatabaseCount('enterprise_role_data_scopes', 1);
        $this->assertDatabaseHas('enterprise_role_data_scopes', [
            'role_id' => $role->id,
            'scope_key' => 'platform:*',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.security.change_applied',
        ]);
    }

    public function test_approval_limit_is_enforced_by_shared_workflow_authorizer(): void
    {
        $maker = $this->superAdmin();
        $checker = $this->superAdmin();
        $approvalRole = $this->roleWithPermissions('LIMITED_APPROVER', ['orders.approve']);
        $approver = User::factory()->create(['is_active' => true]);
        $approver->roles()->attach($approvalRole->id);

        $change = app(EnterpriseSecurityManager::class)->requestApprovalLimit(
            $maker,
            $approvalRole,
            'orders.approve',
            'platform',
            null,
            'KWD',
            '5000.000',
        );
        app(EnterpriseSecurityManager::class)->decideAndApply($change->id, $checker, 'APPROVED');

        $this->assertDatabaseHas('enterprise_approval_limits', [
            'role_id' => $approvalRole->id,
            'permission_code' => 'orders.approve',
            'scope_key' => 'platform:*',
            'currency' => 'KWD',
            'max_amount' => '5000.000',
            'is_active' => true,
        ]);

        $requester = User::factory()->create(['is_active' => true]);
        $engine = app(WorkflowEngine::class);

        $overLimit = $engine->request(
            new WorkflowDefinition('limit_test', 1, [
                new WorkflowStepDefinition('approver', 'orders.approve'),
            ]),
            'order',
            'ORDER-HIGH',
            $requester,
            'platform',
            null,
            'limit-test-high',
            ['amount' => '5000.001', 'currency' => 'KWD'],
        );

        try {
            $engine->decide($overLimit->id, 'approver', $approver, 'APPROVED');
            self::fail('An approval above the configured monetary limit was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $withinLimit = $engine->request(
            new WorkflowDefinition('limit_test', 1, [
                new WorkflowStepDefinition('approver', 'orders.approve'),
            ]),
            'order',
            'ORDER-OK',
            $requester,
            'platform',
            null,
            'limit-test-ok',
            ['amount' => '4999.999', 'currency' => 'KWD'],
        );

        $approved = $engine->decide($withinLimit->id, 'approver', $approver, 'APPROVED');
        $this->assertSame(WorkflowStatus::APPROVED, $approved->status);
    }

    public function test_effective_delegation_grants_only_current_permission_and_expires_automatically(): void
    {
        $checker = $this->superAdmin();
        $delegatorRole = $this->roleWithPermissions('SECURITY_DELEGATOR', [
            'orders.approve',
            'security.delegations.manage',
        ]);
        $delegator = User::factory()->create(['is_active' => true]);
        $delegator->roles()->attach($delegatorRole->id);
        $delegate = User::factory()->create(['is_active' => true]);

        $change = app(EnterpriseSecurityManager::class)->requestDelegation(
            $delegator,
            $delegator,
            $delegate,
            'orders.approve',
            'platform',
            null,
            now()->subMinute()->toIso8601String(),
            now()->addHour()->toIso8601String(),
        );

        app(EnterpriseSecurityManager::class)->decideAndApply($change->id, $checker, 'APPROVED');

        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertTrue($resolver->allows($delegate, 'orders.approve', 'platform', null));

        $delegation = EnterpriseDelegation::query()->firstOrFail();
        $delegation->forceFill(['ends_at' => now()->subSecond()])->save();

        $this->assertFalse($resolver->allows($delegate, 'orders.approve', 'platform', null));
        $this->assertFalse($resolver->allows($delegate, 'orders.edit', 'platform', null));
    }

    public function test_role_data_scope_denies_cross_organization_even_with_permission(): void
    {
        $role = $this->roleWithPermissions('SCOPED_APPROVER', ['orders.approve']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);

        $organizationA = EnterpriseOrganization::query()->firstOrFail();
        $organizationB = EnterpriseOrganization::query()->create([
            'code' => 'second_org',
            'name' => 'Second Organization',
            'is_active' => true,
        ]);

        EnterpriseRoleDataScope::query()->create([
            'role_id' => $role->id,
            'scope_type' => 'organization',
            'scope_id' => $organizationA->id,
            'scope_key' => 'organization:'.$organizationA->id,
            'is_active' => true,
        ]);

        $resolver = app(EnterpriseSecurityAccessResolver::class);

        $this->assertTrue($resolver->allows(
            $user,
            'orders.approve',
            'organization',
            $organizationA->id,
        ));
        $this->assertFalse($resolver->allows(
            $user,
            'orders.approve',
            'organization',
            $organizationB->id,
        ));
    }

    public function test_security_control_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_231700_create_enterprise_security_controls.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_delegations'));
        $this->assertFalse(Schema::hasTable('enterprise_approval_limits'));
        $this->assertFalse(Schema::hasTable('enterprise_role_data_scopes'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('users'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_delegations'));
        $this->assertTrue(Schema::hasTable('enterprise_approval_limits'));
        $this->assertTrue(Schema::hasTable('enterprise_role_data_scopes'));
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /** @param list<string> $permissions */
    private function roleWithPermissions(string $code, array $permissions): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => str_replace('_', ' ', $code),
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);

        $permissionIds = Permission::query()
            ->whereIn('code', $permissions)
            ->pluck('id');

        $this->assertCount(count($permissions), $permissionIds);
        $role->permissions()->sync($permissionIds->all());

        return $role->refresh();
    }
}
