<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P188: the existing Wholesale Customers journey must remain server-authorized.
 */
final class V2WholesaleCustomersSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        app(WholesalePrincipal::class)->storeId();
    }

    public function test_wholesale_customer_navigation_reuses_real_customer_360_and_b2b_clients_in_both_locales(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $fresh = $actor->fresh();

            $accounts = collect(app(AdminNavigation::class)->groupsFor($fresh))
                ->firstWhere('key', 'accounts');
            $this->assertIsArray($accounts);
            $items = collect($accounts['children']);
            $customer = $items->firstWhere('key', 'customer_360');
            $this->assertIsArray($customer);
            $this->assertCount(1, $items->where('key', 'customer_360'));
            $this->assertSame('admin.customer-360.index', $customer['route']);
            $this->assertSame([], $customer['params']);
            $this->assertSame(__('admin.customer_360'), __($customer['label']));
            $this->assertNotContains('b2c_customers', $items->pluck('key'));

            $this->actingAs($fresh)
                ->get(route($customer['route'], $customer['params']))
                ->assertOk()
                ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2b')
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($fresh)
                ->get(route('admin.b2b.module', ['module' => 'clients']))
                ->assertOk()
                ->assertViewHas('navContext', 'b2b_clients')
                ->assertSee(__('admin.b2b_workspace.modules.clients'))
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_only_and_roleless_users_cannot_open_wholesale_clients(): void
    {
        $retailStore = $this->retailStore('V2-2518-RETAIL');
        $retail = User::factory()->create(['is_active' => true]);
        $retail->storeRoleAssignments()->create([
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);
        $roleless = User::factory()->create(['is_active' => true]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([$retail, $roleless] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())
                    ->get(route('admin.b2b.module', ['module' => 'clients']))
                    ->assertForbidden();
            }
        }

        $this->actingAs($roleless)
            ->get(route('admin.customer-360.index'))
            ->assertForbidden();

        $this->actingAs($retail)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2c');
    }

    public function test_explicit_wholesale_account_denial_blocks_the_clients_http_destination(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => DB::table('permissions')->where('code', 'b2b.accounts.view')->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())
            ->get(route('admin.b2b.module', ['module' => 'clients']))
            ->assertForbidden();
    }

    public function test_wholesale_customer_360_list_does_not_expose_retail_only_customer(): void
    {
        $retailStore = $this->retailStore('V2-2518-CUSTOMER-ISOLATION');
        app(PlatformCustomerService::class)->register([
            'name' => 'Retail Isolation Person',
            'email' => 'v2-2518-retail-only@example.test',
            'phone' => '+201000002518',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $retailStore,
        ], 'dashboard');

        $wholesale = $this->globalActor('B2B_ADMIN');
        $this->actingAs($wholesale)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2b')
            ->assertDontSee('v2-2518-retail-only@example.test');
    }

    private function globalActor(string $role): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

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
