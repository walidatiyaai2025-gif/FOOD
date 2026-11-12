<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P185: protect current Customer/Sales navigation while the unified group is pending.
 */
final class V2CustomerSalesSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_customer_navigation_uses_real_authorized_routes_in_both_locales(): void
    {
        $this->store('B2B', 'P185-WHOLESALE');
        $actor = $this->globalActor('CUSTOMER_SERVICE_MANAGER', 'p185-wholesale@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))->keyBy('key');
            $accounts = $groups->get('accounts');

            $this->assertIsArray($accounts);
            $this->assertSame('admin.nav_groups.accounts', $accounts['label']);
            $this->assertNotSame($accounts['label'], __('admin.nav_groups.accounts'));
            $items = collect($accounts['children'])->keyBy('key');
            $this->assertTrue($items->has('customer_groups'));
            $this->assertSame('admin.b2b.customer-groups.index', $items->get('customer_groups')['route']);
            $this->assertFalse($items->has('b2c_customer_groups'));
            $this->assertTrue(Route::has($items->get('customer_groups')['route']));
            $this->assertTrue(collect($groups->get('operations')['children'] ?? [])->contains('key', 'order_operations'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.customer-groups.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_customer_navigation_denies_foreign_store_and_wholesale_groups(): void
    {
        $own = $this->store('B2C', 'P185-OWN');
        $foreign = $this->store('B2C', 'P185-FOREIGN');
        $this->store('B2B', 'P185-WHOLESALE-OTHER');
        $actor = $this->retailActor($own);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $accounts = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'accounts');
            $this->assertIsArray($accounts);
            $items = collect($accounts['children'])->keyBy('key');
            $this->assertTrue($items->has('b2c_customer_groups'));
            $this->assertFalse($items->has('customer_groups'));
            $this->assertSame('admin.b2c.customer-groups.index', $items->get('b2c_customer_groups')['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.customer-groups.index', ['store_id' => $own], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $foreignResponse = $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.customer-groups.index', ['store_id' => $foreign], false));
            $this->assertContains($foreignResponse->getStatusCode(), [403, 404]);
            $wholesaleResponse = $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.customer-groups.index', [], false));
            $this->assertContains($wholesaleResponse->getStatusCode(), [403, 404]);
        }
    }

    public function test_explicit_deny_overrides_sidebar_visibility_without_mutating_roles(): void
    {
        $this->store('B2B', 'P185-DENIED');
        $actor = $this->globalActor('CUSTOMER_SERVICE_MANAGER', 'p185-denied@example.test');
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();
        $permission = Permission::query()->where('code', 'customers.view')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'customer_groups',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('customers.view'));
        $accounts = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'accounts');
        $this->assertFalse(collect($accounts['children'] ?? [])->contains('key', 'customer_groups'));
        $response = $this->actingAs($actor->fresh())
            ->get(route('admin.b2b.customer-groups.index', [], false));
        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertTrue($role->fresh()->permissions()->where('code', 'customers.view')->exists());
    }

    public function test_roleless_and_inactive_users_receive_no_customer_or_sales_links(): void
    {
        $this->store('B2B', 'P185-INACTIVE');
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalActor('CUSTOMER_SERVICE_MANAGER', 'p185-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $items = $groups->flatMap(static fn (array $group): array => $group['children']);
            $this->assertFalse($items->contains('key', 'customer_groups'));
            $this->assertFalse($items->contains('key', 'b2c_customer_groups'));
            $this->assertFalse($items->contains('key', 'order_operations'));
            $this->assertFalse($groups->contains(static fn (array $group): bool => $group['children'] === []));
        }
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::factory()->create(['is_active' => true, 'email' => $email, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor->fresh();
    }

    private function retailActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
