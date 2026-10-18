<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\CustomerServiceCaseManager;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerServiceRoleClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_role_matrix_supports_manager_workflow_and_staff_order_creation(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $supervisor = $this->userWithRole('CUSTOMER_SERVICE_SUPERVISOR');
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $principalStoreId = app(WholesalePrincipal::class)->storeId();

        foreach ([
            'customer_service.team.manage',
            'customers.view',
            'customers.edit',
            'customers.manage',
            'orders.view',
            'orders.create',
            'orders.edit',
            'orders.approve',
            'orders.manage',
            'orders.dispatch',
        ] as $permission) {
            $this->assertTrue($manager->hasPermission($permission), $permission);
        }

        foreach ([$supervisor, $agent] as $staff) {
            $this->assertTrue($staff->hasPermission('orders.view'));
            $this->assertTrue($staff->hasPermission('drivers.tracking.view'));
            $this->assertTrue($staff->hasPermission('orders.create'));
            $this->assertFalse($staff->hasPermission('orders.manage'));
            $this->assertFalse($staff->hasPermission('customer_service.team.manage'));
            $this->assertContains(
                $principalStoreId,
                app(OperationalTenantScope::class)->allowedStoreIds($staff, 'orders.create', 'b2b'),
            );
        }

        $this->assertTrue($manager->hasPermission('drivers.tracking.view'));
        $this->assertContains(
            $principalStoreId,
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'orders.manage', 'b2b'),
        );
        $this->assertContains(
            $principalStoreId,
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'drivers.tracking.view', 'b2b'),
        );
    }

    public function test_manager_can_create_and_manage_only_customer_service_staff(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');

        $this->actingAs($manager)
            ->post(route('admin.erp.customer-service.team.store'), [
                'name' => 'Service Agent One',
                'email' => 'service.agent.one@example.test',
                'password' => 'Temporary123!',
                'locale' => 'ar',
                'role_code' => 'CUSTOMER_SERVICE_AGENT',
            ])
            ->assertRedirect(route('admin.erp.customer-service'));

        $member = User::query()->where('email', 'service.agent.one@example.test')->firstOrFail();
        $this->assertTrue($member->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertFalse($member->hasRole('CUSTOMER_SERVICE_SUPERVISOR'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer_service.team.member_created',
            'user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.erp.customer-service.team.update', ['member' => $member->id]), [
                'role_code' => 'CUSTOMER_SERVICE_SUPERVISOR',
                'is_active' => '0',
                'deactivation_reason' => 'Shift ended',
            ])
            ->assertRedirect(route('admin.erp.customer-service'));

        $member = $member->fresh();
        $this->assertTrue($member->hasRole('CUSTOMER_SERVICE_SUPERVISOR'));
        $this->assertFalse((bool) $member->is_active);
        $this->assertNotNull($member->deactivated_at);
        $this->assertSame('Shift ended', $member->deactivation_reason);

        $this->actingAs($manager)
            ->post(route('admin.erp.customer-service.team.store'), [
                'name' => 'Forbidden Admin',
                'email' => 'forbidden.admin@example.test',
                'password' => 'Temporary123!',
                'locale' => 'en',
                'role_code' => 'B2B_ADMIN',
            ])
            ->assertSessionHasErrors('role_code');

        $privileged = $this->userWithRole('B2B_ADMIN');
        $privileged->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());

        $this->actingAs($manager)
            ->patch(route('admin.erp.customer-service.team.update', ['member' => $privileged->id]), [
                'role_code' => 'CUSTOMER_SERVICE_AGENT',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('member');

        $this->assertTrue($privileged->fresh()->hasRole('B2B_ADMIN'));
    }

    public function test_customer_service_workspace_shows_live_tracking_card_for_customer_service_profiles(): void
    {
        app(WholesalePrincipal::class)->storeId();

        foreach ([
            'CUSTOMER_SERVICE_MANAGER',
            'CUSTOMER_SERVICE_SUPERVISOR',
            'CUSTOMER_SERVICE_AGENT',
        ] as $roleCode) {
            $user = $this->userWithRole($roleCode);

            $this->actingAs($user)
                ->get(route('admin.erp.customer-service'))
                ->assertOk()
                ->assertViewHas('navContext', 'erp_customer_service')
                ->assertSee('data-dashboard-live-driver-map', false)
                ->assertSee('data-actor-kind="mixed"', false)
                ->assertSee(route('admin.driver-live-tracking.feed', ['channel' => 'b2b']), false)
                ->assertSee(route('admin.field-operations.fleet.feed', ['channel' => 'b2b']), false);
        }
    }

    public function test_customer_service_workspace_exposes_total_customer_count_and_admin_routes_land_there(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        app(B2bCustomerService::class)->create(['name' => 'Badge Customer One']);
        app(B2bCustomerService::class)->create(['name' => 'Badge Customer Two']);

        $this->actingAs($manager)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertViewHas('dashboard', function (array $dashboard): bool {
                return (int) ($dashboard['total_customers'] ?? 0) >= 2;
            });

        $this->actingAs($manager)
            ->get(route('admin.index'))
            ->assertRedirect(route('admin.erp.customer-service'));

        $this->actingAs($manager)
            ->get(route('admin.b2b.dashboard'))
            ->assertRedirect(route('admin.erp.customer-service'));
    }

    public function test_customer_service_inbox_unread_count_respects_manager_supervisor_and_agent_visibility(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $supervisor = $this->userWithRole('CUSTOMER_SERVICE_SUPERVISOR');
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $otherAgent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $storeId = app(WholesalePrincipal::class)->storeId();

        $unassignedId = $this->whatsAppConversation($storeId, null, 2);
        $mineId = $this->whatsAppConversation($storeId, $agent->id, 4);
        $otherId = $this->whatsAppConversation($storeId, $otherAgent->id, 7);

        $managerSummary = app(CustomerServiceCaseManager::class)->whatsAppInboxSummary($manager);
        $supervisorSummary = app(CustomerServiceCaseManager::class)->whatsAppInboxSummary($supervisor);
        $agentSummary = app(CustomerServiceCaseManager::class)->whatsAppInboxSummary($agent);

        $this->assertSame(13, $managerSummary['unread_count']);
        $this->assertSame(13, $supervisorSummary['unread_count']);
        $this->assertSame(6, $agentSummary['unread_count']);

        $agentIds = $agentSummary['items']->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $this->assertContains($unassignedId, $agentIds);
        $this->assertContains($mineId, $agentIds);
        $this->assertNotContains($otherId, $agentIds);
    }

    public function test_customer_service_roles_can_open_customer_360_in_wholesale_scope(): void
    {
        foreach ([
            'CUSTOMER_SERVICE_MANAGER',
            'CUSTOMER_SERVICE_SUPERVISOR',
            'CUSTOMER_SERVICE_AGENT',
        ] as $roleCode) {
            $user = $this->userWithRole($roleCode);

            $this->assertTrue($user->hasPermission('customers.view'));
            $this->assertFalse($user->hasPermission('b2b.accounts.view'));

            $this->actingAs($user)
                ->get(route('admin.customer-360.index', ['q' => '']))
                ->assertOk()
                ->assertViewIs('admin.customer-360-index');
        }
    }

    public function test_agent_can_reach_new_order_workflow_without_receiving_order_management(): void
    {
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $principalStoreId = app(WholesalePrincipal::class)->storeId();

        $this->actingAs($agent)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b']))
            ->assertOk()
            ->assertViewHas('newOrderWizard', function (array $wizard): bool {
                return collect($wizard['channels'])->contains(
                    fn (array $channel): bool => $channel['code'] === 'b2b'
                );
            });

        $this->actingAs($agent)
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.operations.orders.quote'), [
                'channel' => 'b2b',
                'store_id' => $principalStoreId,
            ])
            ->assertStatus(422);

        $this->assertFalse($agent->hasPermission('orders.manage'));
    }

    public function test_manager_can_assign_and_unassign_order_followup_only_to_customer_service_team(): void
    {
        $manager = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');
        $outsider = $this->userWithRole('B2B_ADMIN');

        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'Assignment Customer']);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'CS-ASSIGN-1001',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $orderId]), [
                'assigned_user_id' => $agent->id,
                'reason' => 'New order follow-up',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('order_customer_service_assignments', [
            'order_id' => $orderId,
            'assigned_user_id' => $agent->id,
            'assigned_by_user_id' => $manager->id,
            'reason' => 'New order follow-up',
            'unassigned_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_owner.changed',
            'user_id' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $orderId]), [
                'assigned_user_id' => $outsider->id,
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->patch(route('admin.operations.orders.customer-service-owner', ['order' => $orderId]), [
                'assigned_user_id' => null,
                'reason' => 'Returned to queue',
            ])
            ->assertRedirect();

        $this->assertFalse(
            DB::table('order_customer_service_assignments')
                ->where('order_id', $orderId)
                ->whereNull('unassigned_at')
                ->exists(),
        );
    }

    public function test_agent_cannot_manage_customer_service_team(): void
    {
        $agent = $this->userWithRole('CUSTOMER_SERVICE_AGENT');

        $this->actingAs($agent)
            ->post(route('admin.erp.customer-service.team.store'), [
                'name' => 'Unauthorized User',
                'email' => 'unauthorized@example.test',
                'password' => 'Temporary123!',
                'locale' => 'en',
                'role_code' => 'CUSTOMER_SERVICE_AGENT',
            ])
            ->assertForbidden();
    }

    private function whatsAppConversation(int $storeId, ?int $agentId, int $unreadCount): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => $agentId === null ? 'WAITING_FOR_AGENT' : 'OPEN',
            'primary_agent_id' => $agentId,
            'priority' => 0,
            'unread_count' => $unreadCount,
            'waiting_since' => $agentId === null ? now() : null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }
}
