<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\EnterpriseMyWork;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnterpriseMyWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_work_is_scoped_to_authorized_store_and_owner(): void
    {
        $storeA = $this->retailStore('MY-WORK-A');
        $storeB = $this->retailStore('MY-WORK-B');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $other = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $role = Role::query()->firstOrCreate(
            ['code' => 'MY_WORK_SUPPORT'],
            [
                'name' => 'My Work Support',
                'scope' => 'store',
                'is_active' => true,
                'is_system' => false,
            ],
        );
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'whatsapp.conversations.view'],
            ['name' => 'View WhatsApp conversations in authorized scope'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeA->id,
            'role_id' => $role->id,
        ]);

        $mine = $this->conversation($storeA->id, $actor->id);
        $authorizedOther = $this->conversation($storeA->id, $other->id);
        $foreign = $this->conversation($storeB->id, $other->id);

        $service = app(EnterpriseMyWork::class);
        $visible = $service->page(
            $actor,
            '',
            'customer_service',
            'all',
            'all',
            0,
            1,
            '/admin/action-center',
        )['items']->getCollection()->pluck('reference')->all();

        $this->assertContains($mine, $visible);
        $this->assertContains($authorizedOther, $visible);
        $this->assertNotContains($foreign, $visible);

        $owned = $service->page(
            $actor,
            '',
            'customer_service',
            'all',
            'me',
            0,
            1,
            '/admin/action-center',
        )['items']->getCollection()->pluck('reference')->all();

        $this->assertSame([$mine], $owned);
    }

    public function test_action_center_accepts_follow_up_filters_without_replacing_approval_contract(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($actor)
            ->get(route('admin.action-center.index', [
                'module' => 'customer_service',
                'work_state' => 'attention',
                'owner' => 'me',
                'age' => 7,
            ]))
            ->assertOk()
            ->assertSee('My Work &amp; Action Center', false)
            ->assertSee('Approval decisions')
            ->assertSee('Action Center');
    }

    public function test_my_work_dashboard_exposes_authoritative_analytics_and_bounded_export(): void
    {
        $store = $this->retailStore('MY-WORK-DASH');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $reference = $this->conversation($store->id, $actor->id);

        $this->actingAs($actor)
            ->get(route('admin.action-center.index', [
                'module' => 'customer_service',
                'assignee' => 'me',
            ]))
            ->assertOk()
            ->assertSee('My Work &amp; Action Center', false)
            ->assertSee('Workload analytics')
            ->assertSee('Assigned to me')
            ->assertSee($reference);

        $export = $this->actingAs($actor)
            ->get(route('admin.action-center.export', [
                'module' => 'customer_service',
                'assignee' => 'me',
            ]));

        $export
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString($reference, $export->streamedContent());
    }

    public function test_effective_delegation_is_visible_without_creating_a_second_task_ledger(): void
    {
        $store = $this->retailStore('MY-WORK-DELEGATION');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $delegator = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $role = Role::query()->firstOrCreate(
            ['code' => 'MY_WORK_DELEGATE'],
            [
                'name' => 'My Work Delegate',
                'scope' => 'store',
                'is_active' => true,
                'is_system' => false,
            ],
        );
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'whatsapp.conversations.view'],
            ['name' => 'View WhatsApp conversations in authorized scope'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        DB::table('enterprise_delegations')->insert([
            'delegator_id' => $delegator->id,
            'delegate_id' => $actor->id,
            'permission_code' => 'whatsapp.conversations.view',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'scope_key' => 'store:'.$store->id,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reference = $this->conversation($store->id, $delegator->id);

        $result = app(EnterpriseMyWork::class)->page(
            $actor,
            '',
            'customer_service',
            'all',
            'all',
            0,
            1,
            '/admin/action-center',
            ['assignee' => 'me'],
        );

        $item = $result['items']->getCollection()->firstWhere('reference', $reference);
        $this->assertNotNull($item);
        $this->assertSame($delegator->id, $item['delegated_by_id']);
        $this->assertSame($actor->id, $item['delegated_to_id']);
        $this->assertSame($actor->id, $item['effective_owner_id']);
        $this->assertSame(1, $result['summary']['delegated']);
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

    private function conversation(int $storeId, int $ownerId): string
    {
        $publicId = (string) Str::uuid();

        DB::table('whats_app_conversations')->insert([
            'public_id' => $publicId,
            'platform_customer_id' => null,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'OPEN',
            'primary_agent_id' => $ownerId,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }
}
