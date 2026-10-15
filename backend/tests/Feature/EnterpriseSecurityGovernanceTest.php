<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseSecurityGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_privileged_scope_change_requires_independent_checker_then_applies_with_audit(): void
    {
        $maker = $this->userWithRole('SUPER_ADMIN');
        $checker = $this->userWithRole('SUPER_ADMIN');
        $target = User::factory()->create(['is_active' => true]);
        $organizationId = (int) DB::table('enterprise_organizations')->value('id');

        $this->actingAs($maker)
            ->post('/admin/security/enterprise/changes', [
                'operation' => 'data_scope.upsert',
                'request_token' => (string) Str::uuid(),
                'target_user_id' => $target->id,
                'scope_reference' => 'organization:'.$organizationId,
            ])
            ->assertRedirect();

        $workflowId = (int) DB::table('enterprise_workflow_requests')
            ->where('workflow_key', 'enterprise.security.change')
            ->value('id');

        $this->assertDatabaseMissing('enterprise_user_data_scopes', [
            'user_id' => $target->id,
            'scope_type' => 'organization',
            'scope_id' => $organizationId,
        ]);

        $this->actingAs($maker)
            ->post("/admin/security/enterprise/changes/{$workflowId}/decision", [
                'decision' => 'APPROVED',
            ])
            ->assertForbidden();

        $this->actingAs($checker)
            ->post("/admin/security/enterprise/changes/{$workflowId}/decision", [
                'decision' => 'APPROVED',
                'comment' => 'Independent review complete',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enterprise_user_data_scopes', [
            'user_id' => $target->id,
            'scope_type' => 'organization',
            'scope_id' => $organizationId,
            'granted_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.security.data_scope.changed',
            'auditable_id' => $workflowId,
            'user_id' => $checker->id,
        ]);
    }

    public function test_configured_enterprise_scope_denies_cross_organization_checker(): void
    {
        $maker = $this->userWithRole('SUPER_ADMIN');
        $checker = $this->userWithGlobalPermission('roles.manage', 'ORG_SECURITY_CHECKER');
        $target = User::factory()->create(['is_active' => true]);
        $firstOrganizationId = (int) DB::table('enterprise_organizations')->value('id');
        $secondOrganizationId = (int) DB::table('enterprise_organizations')->insertGetId([
            'code' => 'SECOND_ORG',
            'name' => 'Second Organization',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enterprise_user_data_scopes')->insert([
            'user_id' => $checker->id,
            'scope_type' => 'organization',
            'scope_id' => $firstOrganizationId,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'granted_by' => $maker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker)
            ->post('/admin/security/enterprise/changes', [
                'operation' => 'data_scope.upsert',
                'request_token' => (string) Str::uuid(),
                'target_user_id' => $target->id,
                'scope_reference' => 'organization:'.$secondOrganizationId,
            ])
            ->assertRedirect();

        $workflowId = (int) DB::table('enterprise_workflow_requests')
            ->where('workflow_key', 'enterprise.security.change')
            ->latest('id')
            ->value('id');

        $this->actingAs($checker)
            ->post("/admin/security/enterprise/changes/{$workflowId}/decision", [
                'decision' => 'APPROVED',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_user_data_scopes', [
            'user_id' => $target->id,
            'scope_type' => 'organization',
            'scope_id' => $secondOrganizationId,
        ]);
    }

    public function test_temporary_delegation_is_effective_then_expires_automatically(): void
    {
        $maker = $this->userWithRole('SUPER_ADMIN');
        $checker = $this->userWithRole('SUPER_ADMIN');
        $delegate = User::factory()->create(['is_active' => true]);
        $requester = User::factory()->create(['is_active' => true]);

        $this->actingAs($maker)
            ->post('/admin/security/enterprise/changes', [
                'operation' => 'delegation.create',
                'request_token' => (string) Str::uuid(),
                'delegate_id' => $delegate->id,
                'permission_code' => 'roles.manage',
                'scope_reference' => 'global',
                'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $governanceWorkflowId = (int) DB::table('enterprise_workflow_requests')
            ->where('workflow_key', 'enterprise.security.change')
            ->latest('id')
            ->value('id');

        $this->actingAs($checker)
            ->post("/admin/security/enterprise/changes/{$governanceWorkflowId}/decision", [
                'decision' => 'APPROVED',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enterprise_user_delegations', [
            'delegator_id' => $maker->id,
            'delegate_id' => $delegate->id,
            'permission_code' => 'roles.manage',
            'scope_key' => 'global',
            'revoked_at' => null,
        ]);

        $engine = app(WorkflowEngine::class);
        $definition = new WorkflowDefinition('delegated_security_check', 1, [
            new WorkflowStepDefinition('review', 'roles.manage'),
        ]);
        $activeRequest = $engine->request(
            $definition,
            'security_review_fixture',
            'ACTIVE',
            $requester,
            'global',
            null,
            'delegated-security-active',
        );

        $approved = $engine->decide($activeRequest->id, 'review', $delegate, 'APPROVED');
        $this->assertSame('APPROVED', $approved->status->value);

        $this->travel(2)->minutes();

        $expiredRequest = $engine->request(
            $definition,
            'security_review_fixture',
            'EXPIRED',
            $requester,
            'global',
            null,
            'delegated-security-expired',
        );

        $this->expectException(HttpException::class);
        $engine->decide($expiredRequest->id, 'review', $delegate, 'APPROVED');
    }

    public function test_approval_limit_denies_amount_above_ceiling_and_allows_amount_within_ceiling(): void
    {
        $checker = $this->userWithGlobalPermission('workflow.approve', 'LIMIT_CHECKER');
        $requester = User::factory()->create(['is_active' => true]);

        DB::table('enterprise_user_approval_limits')->insert([
            'user_id' => $checker->id,
            'workflow_key' => 'high_value_test',
            'currency' => 'KWD',
            'amount' => '100.0000',
            'scope_type' => 'global',
            'scope_id' => null,
            'scope_key' => 'global',
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'granted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $definition = new WorkflowDefinition('high_value_test', 1, [
            new WorkflowStepDefinition('approve', 'workflow.approve'),
        ]);
        $engine = app(WorkflowEngine::class);

        $aboveLimit = $engine->request(
            $definition,
            'payment_fixture',
            'OVER',
            $requester,
            'global',
            null,
            'limit-over',
            ['amount' => '100.0001', 'currency' => 'KWD'],
        );

        try {
            $engine->decide($aboveLimit->id, 'approve', $checker, 'APPROVED');
            $this->fail('Approval above configured limit must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $withinLimit = $engine->request(
            $definition,
            'payment_fixture',
            'WITHIN',
            $requester,
            'global',
            null,
            'limit-within',
            ['amount' => '99.9999', 'currency' => 'KWD'],
        );

        $approved = $engine->decide($withinLimit->id, 'approve', $checker, 'APPROVED');
        $this->assertSame('APPROVED', $approved->status->value);
    }

    public function test_security_center_renders_enterprise_governance_in_english_and_arabic(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');

        $this->actingAs($admin)
            ->get('/admin/security')
            ->assertOk()
            ->assertSee('Enterprise access governance')
            ->assertSee('Temporary delegations')
            ->assertSee('data-enterprise-security-governance', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get('/admin/security')
            ->assertOk()
            ->assertSee('حوكمة الوصول المؤسسي')
            ->assertSee('التفويضات المؤقتة');
    }

    public function test_governance_schema_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_232000_create_enterprise_security_governance.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_user_delegations'));
        $this->assertFalse(Schema::hasTable('enterprise_user_approval_limits'));
        $this->assertFalse(Schema::hasTable('enterprise_user_data_scopes'));
        $this->assertTrue(Schema::hasTable('users'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_user_delegations'));
        $this->assertTrue(Schema::hasTable('enterprise_user_approval_limits'));
        $this->assertTrue(Schema::hasTable('enterprise_user_data_scopes'));
    }

    private function userWithRole(string $roleCode, string $locale = 'en'): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function userWithGlobalPermission(string $permissionCode, string $roleCode): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['code' => $permissionCode],
            ['name' => $permissionCode],
        );
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
