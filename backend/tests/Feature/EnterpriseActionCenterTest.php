<?php

namespace Tests\Feature;

use App\Enterprise\Workflow\WorkflowDefinition;
use App\Enterprise\Workflow\WorkflowEngine;
use App\Enterprise\Workflow\WorkflowStepDefinition;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseActionCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_actor_sees_safe_overdue_action_and_unauthorized_actor_does_not(): void
    {
        $requester = $this->userWithRole('CUSTOMER_SERVICE_AGENT', 'en', 'action-requester@example.test');
        $approver = $this->userWithRole('B2B_ADMIN', 'en', 'action-approver@example.test');
        $outsider = $this->userWithRole('FINANCE', 'en', 'action-outsider@example.test');

        $this->workflow($requester, [
            'action_title' => 'Order exception review',
            'record_label' => 'Order SO-2048',
            'deep_link' => '/admin/operations/orders?order=SO-2048',
            'due_at' => now()->subHour()->toIso8601String(),
            'private_payload' => 'must-never-render',
        ]);

        $this->workflow($requester, [
            'action_title' => 'External link review',
            'deep_link' => 'https://evil.example/admin/orders/1',
        ], 'external-link-review');

        $this->actingAs($approver)
            ->get(route('admin.action-center.index', ['state' => 'overdue']))
            ->assertOk()
            ->assertSee('Order exception review')
            ->assertSee('Order SO-2048')
            ->assertSee('/admin/operations/orders?order=SO-2048', false)
            ->assertSee('Overdue')
            ->assertDontSee('must-never-render')
            ->assertDontSee('evil.example');

        $this->actingAs($outsider)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertDontSee('Order exception review');
    }

    public function test_decision_is_server_authorized_retry_safe_and_reconciles_the_queue(): void
    {
        $requester = $this->userWithRole('CUSTOMER_SERVICE_AGENT', 'en', 'action-maker@example.test');
        $approver = $this->userWithRole('B2B_ADMIN', 'en', 'action-checker@example.test');
        $outsider = $this->userWithRole('FINANCE', 'en', 'action-finance@example.test');
        $workflow = $this->workflow($requester, ['action_title' => 'Approval target']);

        $url = route('admin.action-center.decide', [
            'workflowRequest' => $workflow->id,
            'stepCode' => 'operations_approval',
        ]);

        $this->actingAs($outsider)->post($url, ['decision' => 'APPROVED'])->assertForbidden();

        $this->actingAs($approver)->post($url, ['decision' => 'APPROVED'])->assertRedirect();
        $this->actingAs($approver)->post($url, ['decision' => 'APPROVED'])->assertRedirect();

        $this->assertDatabaseHas('enterprise_workflow_requests', [
            'id' => $workflow->id,
            'status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('enterprise_workflow_steps', [
            'workflow_request_id' => $workflow->id,
            'step_code' => 'operations_approval',
            'status' => 'APPROVED',
            'actor_id' => $approver->id,
        ]);

        $this->actingAs($approver)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertDontSee('Approval target');
    }

    public function test_maker_checker_and_arabic_direction_are_enforced(): void
    {
        $requester = $this->userWithRole('B2B_ADMIN', 'ar', 'action-ar@example.test');
        $this->workflow($requester, [
            'action_title' => 'مراجعة اعتماد',
        ]);

        $this->actingAs($requester)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مركز الإجراءات')
            ->assertDontSee('مراجعة اعتماد');
    }

    public function test_store_scoped_action_does_not_leak_to_another_store_assignment(): void
    {
        $requester = $this->userWithRole('CUSTOMER_SERVICE_AGENT', 'en', 'action-store-requester@example.test');
        $approver = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $storeA = $this->retailStore('ACTION-A');
        $storeB = $this->retailStore('ACTION-B');
        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        UserStoreRole::query()->create([
            'user_id' => $approver->id,
            'store_id' => $storeA->id,
            'role_id' => $retailRole->id,
        ]);

        $allowed = $this->workflow($requester, ['action_title' => 'Assigned store action'], 'store-a', 'store', $storeA->id);
        $blocked = $this->workflow($requester, ['action_title' => 'Foreign store action'], 'store-b', 'store', $storeB->id);

        $this->actingAs($approver)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertSee('Assigned store action')
            ->assertDontSee('Foreign store action');

        $this->actingAs($approver)
            ->post(route('admin.action-center.decide', [
                'workflowRequest' => $blocked->id,
                'stepCode' => 'operations_approval',
            ]), ['decision' => 'APPROVED'])
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_workflow_requests', ['id' => $allowed->id, 'status' => 'PENDING']);
        $this->assertDatabaseHas('enterprise_workflow_requests', ['id' => $blocked->id, 'status' => 'PENDING']);
    }

    public function test_queue_is_bounded_and_paginated(): void
    {
        $requester = $this->userWithRole('CUSTOMER_SERVICE_AGENT', 'en', 'action-bounded-requester@example.test');
        $approver = $this->userWithRole('B2B_ADMIN', 'en', 'action-bounded-approver@example.test');

        foreach (range(1, 31) as $index) {
            $this->workflow($requester, [
                'action_title' => "Approval item {$index}",
                'record_label' => "SO-{$index}",
            ], "action-center-{$index}");
        }

        $response = $this->actingAs($approver)->get(route('admin.action-center.index'));
        $response->assertOk()->assertSee('Approval item 1')->assertDontSee('Approval item 31');

        $this->actingAs($approver)
            ->get(route('admin.action-center.index', ['actions_page' => 2]))
            ->assertOk()
            ->assertSee('Approval item 31');
    }

    /** @param array<string, mixed> $context */
    private function workflow(
        User $requester,
        array $context,
        string $key = 'action-center-default',
        string $scopeType = 'organization',
        ?int $scopeId = null,
    ): EnterpriseWorkflowRequest {
        return app(WorkflowEngine::class)->request(
            new WorkflowDefinition(
                'order_exception',
                1,
                [new WorkflowStepDefinition('operations_approval', 'orders.approve')],
            ),
            'order',
            strtoupper($key),
            $requester,
            $scopeType,
            $scopeId,
            $key,
            $context,
        );
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
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
