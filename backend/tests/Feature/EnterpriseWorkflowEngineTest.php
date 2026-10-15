<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStatus;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseWorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_is_idempotent_and_creates_versioned_steps(): void
    {
        $requester = User::factory()->create();
        $definition = $this->definition();

        $first = app(WorkflowEngine::class)->request(
            $definition,
            'purchase_order',
            'PO-100',
            $requester,
            'organization',
            10,
            'po-100-submit-v1',
            ['amount' => '4500.000'],
        );
        $second = app(WorkflowEngine::class)->request(
            $definition,
            'purchase_order',
            'PO-100',
            $requester,
            'organization',
            10,
            'po-100-submit-v1',
            ['amount' => '4500.000'],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertCount(2, $first->steps);
        $this->assertSame(1, $first->definition_version);
        $this->assertDatabaseCount('enterprise_workflow_requests', 1);
        $this->assertDatabaseCount('enterprise_workflow_steps', 2);
    }

    public function test_maker_cannot_approve_own_request_and_unauthorized_actor_is_denied(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $request = $this->request($requester);

        $this->expectException(HttpException::class);
        app(WorkflowEngine::class)->decide($request->id, 'checker', $requester, 'APPROVED');
    }

    public function test_authorized_checker_and_approver_complete_workflow_in_sequence(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $checker = $this->userWithGlobalPermission('workflow.check');
        $approver = $this->userWithGlobalPermission('workflow.approve');
        $engine = app(WorkflowEngine::class);
        $request = $this->request($requester);

        $afterCheck = $engine->decide($request->id, 'checker', $checker, 'APPROVED');
        $this->assertSame(WorkflowStatus::PENDING, $afterCheck->status);

        $approved = $engine->decide($request->id, 'approver', $approver, 'APPROVED');
        $this->assertSame(WorkflowStatus::APPROVED, $approved->status);
        $this->assertNotNull($approved->completed_at);

        $retry = $engine->decide($request->id, 'approver', $approver, 'APPROVED');
        $this->assertSame(WorkflowStatus::APPROVED, $retry->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.workflow.decision_recorded',
            'auditable_id' => $request->id,
        ]);
    }

    public function test_rejection_is_terminal_and_later_step_cannot_run(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $checker = $this->userWithGlobalPermission('workflow.check');
        $approver = $this->userWithGlobalPermission('workflow.approve');
        $engine = app(WorkflowEngine::class);
        $request = $this->request($requester);

        $rejected = $engine->decide($request->id, 'checker', $checker, 'REJECTED');
        $this->assertSame(WorkflowStatus::REJECTED, $rejected->status);

        $this->expectException(LogicException::class);
        $engine->decide($request->id, 'approver', $approver, 'APPROVED');
    }

    public function test_requester_can_cancel_pending_workflow_idempotently(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $request = $this->request($requester);
        $engine = app(WorkflowEngine::class);

        $cancelled = $engine->cancel($request->id, $requester);
        $retry = $engine->cancel($request->id, $requester);

        $this->assertSame(WorkflowStatus::CANCELLED, $cancelled->status);
        $this->assertSame(WorkflowStatus::CANCELLED, $retry->status);
    }

    public function test_workflow_migration_is_additive_and_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_15_130500_create_enterprise_workflow_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_workflow_requests'));
        $this->assertTrue(Schema::hasTable('users'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_workflow_steps'));
        $this->assertTrue(Schema::hasTable('users'));
    }

    private function request(User $requester)
    {
        return app(WorkflowEngine::class)->request(
            $this->definition(),
            'purchase_order',
            'PO-'.(string) $requester->id,
            $requester,
            'organization',
            10,
            'workflow-'.(string) $requester->id,
        );
    }

    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition('two_step_approval', 1, [
            new WorkflowStepDefinition('checker', 'workflow.check'),
            new WorkflowStepDefinition('approver', 'workflow.approve'),
        ]);
    }

    private function userWithGlobalPermission(string $permissionCode): User
    {
        $permission = Permission::query()->create([
            'code' => $permissionCode,
            'name' => $permissionCode,
        ]);
        $role = Role::query()->create([
            'code' => 'ROLE_'.strtoupper(str_replace('.', '_', $permissionCode)),
            'name' => $permissionCode,
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        DB::table('permission_role')->insert([
            'permission_id' => $permission->id,
            'role_id' => $role->id,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        DB::table('role_user')->insert([
            'role_id' => $role->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }
}
