<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2CustomersSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_customers_group_preserves_order_and_authorized_store_links_in_both_languages(): void
    {
        $storeA = $this->retailStore('A');
        $storeB = $this->retailStore('B');
        $retail = $this->retailManager($storeA);

        $group = collect(app(AdminNavigation::class)->groupsFor($retail))
            ->firstWhere('key', 'accounts');

        $this->assertIsArray($group);
        $this->assertSame('admin.nav_groups.accounts', $group['label']);
        $this->assertArrayNotHasKey('route', $group, 'The group heading must never grant access.');
        $items = collect($group['children'])->keyBy('key');

        $this->assertSame(
            ['customer_360', 'b2c_customers', 'b2c_customer_groups'],
            array_keys($items->all()),
        );
        $this->assertSame('admin.customer-360.index', $items['customer_360']['route']);
        $sales = collect(app(AdminNavigation::class)->groupsFor($retail))->firstWhere('key', 'sales');
        $this->assertSame('admin.nav_groups.sales', $sales['label']);
        $this->assertSame(['sales_quotations'], collect($sales['children'])->pluck('key')->all());
        $this->assertSame('admin.sales-quotations.index', $sales['children'][0]['route']);
        $this->assertSame('admin.b2c.module', $items['b2c_customers']['route']);
        $this->assertSame(
            ['module' => 'customers', 'store_id' => (string) $storeA],
            $items['b2c_customers']['params'],
        );
        $this->assertSame('admin.b2c.customer-groups.index', $items['b2c_customer_groups']['route']);
        $this->assertFalse($items->has('customer_groups'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $retail->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail)
                ->get(route($items['b2c_customers']['route'], $items['b2c_customers']['params']))
                ->assertOk()
                ->assertViewHas('navContext', 'b2c_customers')
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retail)
                ->get(route('admin.b2c.customer-groups.index'))
                ->assertOk();
        }

        $this->actingAs($retail)
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $storeB]))
            ->assertNotFound();
    }

    public function test_wholesale_customers_group_exposes_only_wholesale_children_and_real_http_routes(): void
    {
        // The Groups HTTP route requires a real authorized Wholesale store. The
        // canonical principal service initializes it for an isolated fresh DB.
        app(WholesalePrincipal::class)->storeId();

        $wholesale = $this->user('Wholesale Accounts', 'v2-p186-wholesale@example.test');
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $group = collect(app(AdminNavigation::class)->groupsFor($wholesale))
            ->firstWhere('key', 'accounts');

        $this->assertIsArray($group);
        $items = collect($group['children'])->keyBy('key');
        $this->assertSame(['customer_360', 'customer_groups'], array_keys($items->all()));
        $sales = collect(app(AdminNavigation::class)->groupsFor($wholesale))->firstWhere('key', 'sales');
        $this->assertSame('admin.nav_groups.sales', $sales['label']);
        $this->assertSame(['sales_quotations'], collect($sales['children'])->pluck('key')->all());
        $this->assertSame('admin.sales-quotations.index', $sales['children'][0]['route']);
        $this->assertSame('admin.b2b.customer-groups.index', $items['customer_groups']['route']);
        $this->assertFalse($items->has('b2c_customers'));
        $this->assertFalse($items->has('b2c_customer_groups'));

        $this->actingAs($wholesale)
            ->get(route('admin.b2b.customer-groups.index'))
            ->assertOk();
        $this->actingAs($wholesale)
            ->get(route('admin.customer-360.index'))
            ->assertOk();

        $retailStore = $this->retailStore('FOREIGN');
        $this->actingAs($wholesale)
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $retailStore]))
            ->assertForbidden();
    }

    public function test_empty_customers_group_is_suppressed_and_direct_routes_still_deny(): void
    {
        $storeId = $this->retailStore('DENIED');
        $driver = $this->user('Mobile Driver', 'v2-p186-driver@example.test');
        $driver->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->assertFalse(
            collect(app(AdminNavigation::class)->groupsFor($driver))->contains('key', 'accounts'),
        );

        $this->actingAs($driver)
            ->get(route('admin.customer-360.index'))
            ->assertForbidden();
        $this->actingAs($driver)
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $storeId]))
            ->assertForbidden();
    }

    public function test_expired_retail_role_cannot_retain_a_customers_group_or_http_authority(): void
    {
        $storeId = $this->retailStore('EXPIRED');
        $manager = $this->retailManager($storeId, 'v2-p186-expired@example.test');

        Role::query()->where('code', 'B2C_STORE_ADMIN')->update(['is_active' => false]);

        $this->assertFalse(
            collect(app(AdminNavigation::class)->groupsFor($manager))->contains('key', 'accounts'),
        );
        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $storeId]))
            ->assertForbidden();
    }

    private function retailStore(string $suffix): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-P186-'.$suffix,
            'name' => 'V2 P186 '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailManager(int $storeId, string $email = 'v2-p186-retail@example.test'): User
    {
        $manager = $this->user('Retail Accounts', $email);
        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $manager;
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
    }
}
