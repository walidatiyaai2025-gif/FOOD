<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P190: customer-group navigation is a projection of real, scoped HTTP access.
 * Existing B2B and B2C workflows are reused, never reimplemented in Sidebar.
 */
final class V2CustomerGroupsSidebarNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_mixed_channel_customer_group_links_resolve_to_real_scoped_pages_in_both_locales(): void
    {
        $owned = $this->retailStore('V2-2520-OWNED');
        $foreign = $this->retailStore('V2-2520-FOREIGN');
        $actor = $this->mixedChannelOperator($owned);

        DB::table('commercial_customer_groups')->insert([
            'store_id' => $owned,
            'name' => 'V2 P190 Owned Group',
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commercial_customer_groups')->insert([
            'store_id' => $foreign,
            'name' => 'V2 P190 Foreign Group',
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $fresh = $actor->fresh();
            $accounts = collect(app(AdminNavigation::class)->groupsFor($fresh))
                ->firstWhere('key', 'accounts');
            $this->assertIsArray($accounts);
            $links = collect($accounts['children']);
            $wholesale = $links->firstWhere('key', 'customer_groups');
            $retail = $links->firstWhere('key', 'b2c_customer_groups');

            $this->assertIsArray($wholesale);
            $this->assertIsArray($retail);
            $this->assertCount(1, $links->where('key', 'customer_groups'));
            $this->assertCount(1, $links->where('key', 'b2c_customer_groups'));
            $this->assertSame('admin.b2b.customer-groups.index', $wholesale['route']);
            $this->assertSame('admin.b2c.customer-groups.index', $retail['route']);
            $this->assertSame([], $wholesale['params']);
            $this->assertSame([], $retail['params']);
            $this->assertSame(__('customer_groups.wholesale_title'), __($wholesale['label']));
            $this->assertSame(__('customer_groups.retail_title'), __($retail['label']));

            $this->actingAs($fresh)
                ->get(route($wholesale['route'], $wholesale['params']))
                ->assertOk()
                ->assertViewHas('navContext', 'customer_groups')
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($fresh)
                ->get(route($retail['route'], ['store_id' => $owned]))
                ->assertOk()
                ->assertViewHas('navContext', 'b2c_customer_groups')
                ->assertSee('V2 P190 Owned Group')
                ->assertDontSee('V2 P190 Foreign Group')
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($fresh)
                ->get(route($retail['route'], ['store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_roleless_and_globally_denied_users_cannot_gain_group_access_from_sidebar(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $retailStore = $this->retailStore('V2-2520-DENIED');
        $retailActor = User::factory()->create(['is_active' => true]);
        $retailActor->storeRoleAssignments()->create([
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $retailActor->id,
            'permission_id' => DB::table('permissions')->where('code', 'customers.view')->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailActor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([$roleless, $retailActor] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key');
                $this->assertNotContains('customer_groups', $items);
                $this->assertNotContains('b2c_customer_groups', $items);
            }

            $this->actingAs($roleless->fresh())
                ->get(route('admin.b2b.customer-groups.index'))
                ->assertForbidden();
            $this->actingAs($roleless->fresh())
                ->get(route('admin.b2c.customer-groups.index', ['store_id' => $retailStore]))
                ->assertForbidden();
            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2c.customer-groups.index', ['store_id' => $retailStore]))
                ->assertForbidden();
        }
    }

    public function test_inactive_store_cannot_be_reached_by_a_legacy_customer_group_link(): void
    {
        $store = $this->retailStore('V2-2520-INACTIVE');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->storeRoleAssignments()->create([
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);
        DB::table('stores')->where('id', $store)->update(['is_active' => false]);

        $links = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('b2c_customer_groups', $links);
        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.customer-groups.index', ['store_id' => $store]))
            ->assertNotFound();
    }

    private function mixedChannelOperator(int $retailStoreId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $actor->storeRoleAssignments()->create([
            'store_id' => $retailStoreId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
