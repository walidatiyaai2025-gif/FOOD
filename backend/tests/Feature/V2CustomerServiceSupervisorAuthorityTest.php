<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Services\CustomerServiceCaseManager;
use App\Services\CustomerServiceTeamManager;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T122R6 / #2686 — genuine service/supervisor HTTP authority and separation
 * of conversation oversight from staff identity administration.
 *
 * The canonical role baseline, navigation, AR/EN, deny and inactive
 * properties are already covered by V2CustomerServiceSupervisorAuthorizationAuditTest;
 * do not retest or rebuild that baseline here.
 */
final class V2CustomerServiceSupervisorAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        app(WholesalePrincipal::class)->storeId();

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
        ])->save();
    }

    public function test_supervisor_can_open_real_wholesale_service_and_whatsapp_supervisor_workspaces_in_both_locales(): void
    {
        $actor = $this->supervisor();
        $wholesale = app(WholesalePrincipal::class)->storeId();

        $this->assertContains(
            $wholesale,
            app(OperationalTenantScope::class)->allowedStoreIds($actor, 'whatsapp.conversations.view', 'b2b'),
        );
        $this->assertContains(
            $wholesale,
            app(CustomerServiceCaseManager::class)->viewStoreIds($actor),
        );
        $this->assertTrue($actor->hasPermission('whatsapp.customer_service.supervise'));
        $this->assertFalse(app(CustomerServiceTeamManager::class)->canManage($actor));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.customer-service'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-supervisor.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_foreign_retail_store_cannot_be_selected_by_wholesale_supervisor_on_either_workspace(): void
    {
        $actor = $this->supervisor();
        $retail = $this->retailStore();

        $this->assertNotContains($retail, app(CustomerServiceCaseManager::class)->viewStoreIds($actor));
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($actor, 'whatsapp.conversations.view', 'b2c'),
        );

        foreach (['admin.erp.customer-service', 'admin.whatsapp-supervisor.index'] as $route) {
            $this->actingAs($actor)
                ->get(route($route, ['store_id' => $retail]))
                ->assertNotFound();
        }
    }

    public function test_global_supervision_deny_blocks_real_analytics_but_keeps_ordinary_service_workspace(): void
    {
        $actor = $this->supervisor();
        $id = Permission::query()->where('code', 'whatsapp.customer_service.supervise')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('whatsapp.customer_service.supervise'));
        $this->assertTrue($actor->hasPermission('whatsapp.conversations.view'));
        $this->assertTrue($actor->hasPermission('customers.view'));

        $this->actingAs($actor)
            ->get(route('admin.whatsapp-supervisor.index'))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk();
    }

    public function test_whatsapp_agent_supervision_cannot_create_or_modify_team_accounts_without_team_manage(): void
    {
        $actor = $this->supervisor();
        $target = User::factory()->create(['is_active' => true]);
        $target->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $previousName = $target->name;

        $this->assertTrue($actor->hasPermission('whatsapp.agent_status.manage'));
        $this->assertFalse($actor->hasPermission('customer_service.team.manage'));
        $this->assertFalse(app(CustomerServiceTeamManager::class)->canManage($actor));

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.team.store'), [
                'name' => 'Unauthorized New Supervisor',
                'email' => 'p2686-team-leak@example.test',
                'password' => 'Test-Only-Not-A-Real-Credential-2686',
                'locale' => 'en',
                'role_code' => 'CUSTOMER_SERVICE_SUPERVISOR',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->patch(route('admin.erp.customer-service.team.update', ['member' => $target->id]), [
                'name' => 'Unauthorized Rename',
                'is_active' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'p2686-team-leak@example.test']);
        $this->assertSame($previousName, $target->fresh()->name);
        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertTrue($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
    }

    private function supervisor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P2686-FOREIGN-RETAIL',
            'name' => 'P2686 Foreign Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
