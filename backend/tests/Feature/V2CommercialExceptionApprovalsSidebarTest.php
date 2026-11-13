<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CommercialExceptionApprovalsSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_p197_link_requires_joint_permissions_and_real_route(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('sales', $registry['items']['commercial_exception_approvals']['group']);
        $this->assertSame(['orders.view', 'orders.customer_service_discount', 'orders.approve'],
            $registry['items']['commercial_exception_approvals']['permissions']);
        $this->assertSame('sales',
            app(AdminSidebarManager::class)->layoutData()['items']['commercial_exception_approvals']['group_key']);
        $store = app(WholesalePrincipal::class)->storeId();
        $reviewer = $this->globalActor('CUSTOMER_SERVICE_MANAGER');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $reviewer->forceFill(['locale' => $locale])->save();
            $item = $this->sidebarItem($reviewer->fresh());
            $this->assertIsArray($item);
            $this->assertSame('admin.operations.orders.index', $item['route']);
            $this->assertSame([
                'channel' => 'b2b', 'store_id' => (string) $store,
                'discount_view' => 'pending', 'action_required' => '0',
            ], $item['params']);
            $this->actingAs($reviewer->fresh())->get(route($item['route'], $item['params']))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_denied_approver_cannot_inherit_link_or_http_authority_from_catalog(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $user = $this->globalActor('B2B_ADMIN');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('code', 'orders.approve')->value('id'),
            'scope_key' => 'global', 'store_id' => null, 'effect' => 'deny',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
        $this->assertNull($this->sidebarItem($user->fresh()));
        $this->actingAs($user->fresh())->get($this->inboxUrl('b2b', $store))->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertNull($this->sidebarItem($roleless));
        $this->actingAs($roleless)->get($this->inboxUrl('b2b', $store))->assertForbidden();
    }

    public function test_retail_scope_cannot_be_reused_for_foreign_store_or_wholesale(): void
    {
        $own = $this->store('P197-OWN');
        $foreign = $this->store('P197-FOREIGN');
        $b2b = app(WholesalePrincipal::class)->storeId();
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id, 'store_id' => $own, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Scoped fixture only. No production role permission is changed.
        foreach (['orders.view', 'orders.customer_service_discount', 'orders.approve'] as $code) {
            $id = DB::table('permissions')->where('code', $code)->value('id');
            $this->assertNotNull($id);
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
        }
        $item = $this->sidebarItem($user->fresh());
        $this->assertIsArray($item);
        $this->assertSame('b2c', $item['params']['channel']);
        $this->assertSame((string) $own, $item['params']['store_id']);
        $this->actingAs($user->fresh())->get(route($item['route'], $item['params']))->assertOk();
        $this->actingAs($user->fresh())->get($this->inboxUrl('b2c', $foreign))->assertNotFound();
        $this->actingAs($user->fresh())->get($this->inboxUrl('b2b', $b2b))->assertForbidden();

        DB::table('admin_sidebar_groups')->updateOrInsert(['group_key' => 'sales'], [
            'label_ar' => null, 'label_en' => null, 'icon' => '▤', 'sort_order' => 52,
            'is_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull($this->sidebarItem($user->fresh()));
        $this->actingAs($user->fresh())->get($this->inboxUrl('b2c', $own))->assertOk();
        DB::table('admin_sidebar_groups')->where('group_key', 'sales')->update(['is_enabled' => true]);
        Role::query()->where('code', 'B2C_STORE_ADMIN')->update(['is_active' => false]);
        $this->assertNull($this->sidebarItem($user->fresh()));
        $this->actingAs($user->fresh())->get($this->inboxUrl('b2c', $own))->assertForbidden();
    }

    /** @return array<string,mixed>|null */
    private function sidebarItem(User $user): ?array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group) => $group['children'])
            ->firstWhere('key', 'commercial_exception_approvals');
    }

    private function inboxUrl(string $channel, int $store): string
    {
        return route('admin.operations.orders.index', [
            'channel' => $channel, 'store_id' => $store,
            'discount_view' => 'pending', 'action_required' => 0,
        ]);
    }

    private function globalActor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE', 'code' => $code,
            'name' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
