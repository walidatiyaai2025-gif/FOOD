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
 * V2 P189: Retail Customers navigation cannot bypass its real store-scoped GET.
 */
final class V2RetailCustomersSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_customer_link_uses_only_the_authorized_store_and_real_page_in_both_locales(): void
    {
        $owned = $this->retailStore('V2-2519-OWN');
        $foreign = $this->retailStore('V2-2519-FOREIGN');
        $actor = $this->storeActor($owned);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $fresh = $actor->fresh();
            $accounts = collect(app(AdminNavigation::class)->groupsFor($fresh))
                ->firstWhere('key', 'accounts');
            $this->assertIsArray($accounts);
            $items = collect($accounts['children']);
            $retail = $items->firstWhere('key', 'b2c_customers');
            $this->assertIsArray($retail);
            $this->assertCount(1, $items->where('key', 'b2c_customers'));
            $this->assertSame('admin.b2c.module', $retail['route']);
            $this->assertSame([
                'module' => 'customers',
                'store_id' => (string) $owned,
            ], $retail['params']);
            $this->assertSame(__('admin.b2c_workspace.modules.customers'), __($retail['label']));
            $this->assertNotContains('customer_groups', $items->pluck('key'));

            $this->actingAs($fresh)
                ->get(route($retail['route'], $retail['params']))
                ->assertOk()
                ->assertViewHas('navContext', 'b2c_customers')
                ->assertViewHas('storeId', $owned)
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($fresh)
                ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_roleless_wholesale_only_and_explicit_deny_cannot_grant_retail_customers(): void
    {
        $store = $this->retailStore('V2-2519-DENIED');
        $roleless = User::factory()->create(['is_active' => true]);
        $wholesale = User::factory()->create(['is_active' => true]);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $denied = $this->storeActor($store);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $denied->id,
            'permission_id' => DB::table('permissions')->where('code', 'customers.view')->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $denied->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([$roleless, $wholesale, $denied] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key');
                $this->assertNotContains('b2c_customers', $keys);
            }

            foreach ([$roleless, $wholesale, $denied] as $actor) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $store]))
                    ->assertForbidden();
            }
        }
    }

    public function test_inactive_retail_store_is_not_a_valid_customer_workspace(): void
    {
        $store = $this->retailStore('V2-2519-INACTIVE');
        $actor = $this->storeActor($store);
        DB::table('stores')->where('id', $store)->update(['is_active' => false]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('b2c_customers', $keys);
        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $store]))
            ->assertForbidden();
    }

    private function storeActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->storeRoleAssignments()->create([
            'store_id' => $storeId,
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
