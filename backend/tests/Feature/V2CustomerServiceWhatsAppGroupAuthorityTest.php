<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CustomerServiceWhatsAppGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_whatsapp_runtime_hides_empty_customer_service_group_and_blocks_direct_inbox(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');

        $this->assertNull($this->serviceGroup($admin));

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertNotFound();

        // Even an authorized user may not reach an unavailable module via a bookmarked URL.
        $this->enableInbox();
        $this->assertNotNull($this->serviceGroup($admin));

        WhatsAppModuleState::query()->findOrFail(1)
            ->forceFill(['capabilities' => ['agent_inbox' => false]])
            ->save();

        $this->assertNull($this->serviceGroup($admin));
        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertNotFound();
    }

    public function test_operational_group_reuses_one_authorized_inbox_destination_in_arabic_and_english(): void
    {
        $this->enableInbox();
        $admin = $this->globalUser('SUPER_ADMIN');

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $group = $this->serviceGroup($admin);
            $this->assertNotNull($group);
            $this->assertSame('admin.nav_groups.customer_service', $group['label']);
            $this->assertCount(1, $group['children']);
            $this->assertSame('whatsapp_inbox', $group['children'][0]['key']);
            $this->assertSame('admin.whatsapp-inbox.index', $group['children'][0]['route']);
            $this->assertSame([], $group['children'][0]['params']);

            $this->actingAs($admin)
                ->get(route('admin.whatsapp-inbox.index'))
                ->assertOk()
                ->assertSee($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', false)
                ->assertSee('data-nav-group="customer_service"', false)
                ->assertSee(__('admin.nav_groups.customer_service'))
                ->assertSee(route('admin.whatsapp-inbox.index'), false);
        }
    }

    public function test_store_scoped_customer_support_sees_only_its_authorized_inbox_and_foreign_store_is_denied(): void
    {
        $this->enableInbox();
        $ownStore = $this->store('V2-WA-GROUP-OWN');
        $foreignStore = $this->store('V2-WA-GROUP-FOREIGN');
        $agent = $this->retailSupportUser($ownStore);

        $group = $this->serviceGroup($agent);
        $this->assertNotNull($group);
        $this->assertSame(['whatsapp_inbox'], array_column($group['children'], 'key'));
        $this->assertTrue($agent->hasPermission('whatsapp.inbox.view', $ownStore));
        $this->assertFalse($agent->hasPermission('whatsapp.inbox.view', $foreignStore));

        $this->actingAs($agent)
            ->get(route('admin.whatsapp-inbox.index', ['store_id' => $ownStore]))
            ->assertOk()
            ->assertSee('data-nav-group="customer_service"', false);

        $this->actingAs($agent)
            ->get(route('admin.whatsapp-inbox.index', ['store_id' => $foreignStore]))
            ->assertNotFound();

        DB::table('stores')->where('id', $ownStore)->update(['is_active' => false]);

        $this->assertNull($this->serviceGroup($agent), 'A disabled retail store must not keep a navigable Inbox.');
        $response = $this->actingAs($agent)
            ->get(route('admin.whatsapp-inbox.index', ['store_id' => $ownStore]));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_unprivileged_actor_gets_neither_group_nor_inbox_even_when_module_is_operational(): void
    {
        $this->enableInbox();
        $outsider = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        $this->assertNull($this->serviceGroup($outsider));
        $response = $this->actingAs($outsider)
            ->get(route('admin.whatsapp-inbox.index'));

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_presentation_visibility_does_not_create_or_revoke_runtime_inbox_authority(): void
    {
        $this->enableInbox();
        $storeId = $this->store('V2-WA-GROUP-PRESENTATION');
        $agent = $this->retailSupportUser($storeId);
        $this->assertNotNull($this->serviceGroup($agent));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->value('id'),
            'item_key' => 'whatsapp_inbox',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->serviceGroup($agent), 'Group with zero visible authorized children must disappear.');
        $this->actingAs($agent)
            ->get(route('admin.whatsapp-inbox.index', ['store_id' => $storeId]))
            ->assertOk();
    }

    /** @return array<string,mixed>|null */
    private function serviceGroup(User $user): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'customer_service');
    }

    private function enableInbox(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
        ])->save();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailSupportUser(int $storeId): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
