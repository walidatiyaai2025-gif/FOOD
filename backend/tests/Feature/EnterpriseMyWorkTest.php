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
            ->assertSee('My Work &amp; Follow-up', false)
            ->assertSee('Approval decisions')
            ->assertSee('Action Center');
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
