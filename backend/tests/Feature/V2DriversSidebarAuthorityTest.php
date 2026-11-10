<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2DriversSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_drivers_entry_opens_only_its_authorized_store_in_both_locales(): void
    {
        $storeA = $this->retailStore('A');
        $storeB = $this->retailStore('B');
        $this->driver('Driver Visible In A', 'driver-a-p262@example.test', $storeA);
        $this->driver('Driver Hidden In B', 'driver-b-p262@example.test', $storeB);
        $user = $this->retailManager($storeA);

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children']);
        $entry = $items->firstWhere('key', 'b2c_drivers');

        $this->assertNotNull($entry);
        $this->assertSame('admin.b2c.module', $entry['route']);
        $this->assertSame('drivers', $entry['params']['module']);
        $this->assertSame((string) $storeA, $entry['params']['store_id']);
        $this->assertFalse($items->contains('key', 'b2b_drivers'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();

            $this->actingAs($user)
                ->get(route($entry['route'], $entry['params']))
                ->assertOk()
                ->assertViewHas('navContext', 'b2c_drivers')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('Driver Visible In A')
                ->assertDontSee('Driver Hidden In B');
        }
    }

    public function test_driver_direct_urls_enforce_retail_store_and_wholesale_channel_boundaries(): void
    {
        $storeA = $this->retailStore('SCOPE-A');
        $storeB = $this->retailStore('SCOPE-B');
        $retail = $this->retailManager($storeA);

        $this->actingAs($retail)
            ->get(route('admin.b2c.module', ['module' => 'drivers', 'store_id' => $storeB]))
            ->assertNotFound();
        $this->actingAs($retail)
            ->get(route('admin.b2b.module', ['module' => 'drivers']))
            ->assertForbidden();

        $wholesale = $this->user('Wholesale P262', 'wholesale-p262@example.test');
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $items = collect(app(AdminNavigation::class)->groupsFor($wholesale))
            ->flatMap(fn (array $group) => $group['children']);
        $this->assertTrue($items->contains('key', 'b2b_drivers'));
        $this->assertFalse($items->contains('key', 'b2c_drivers'));

        $this->actingAs($wholesale)
            ->get(route('admin.b2b.module', ['module' => 'drivers']))
            ->assertOk()
            ->assertViewHas('navContext', 'b2b_drivers');
        $this->actingAs($wholesale)
            ->get(route('admin.b2c.module', ['module' => 'drivers', 'store_id' => $storeA]))
            ->assertForbidden();
    }

    public function test_driver_account_and_unsupported_retail_profile_never_receive_drivers_dashboard_access(): void
    {
        $store = $this->retailStore('DENIED');
        $actor = $this->user('Mobile Driver P262', 'mobile-driver-p262@example.test');
        $actor->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $items = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(fn (array $group) => $group['children']);
        $this->assertFalse($items->contains('key', 'b2c_drivers'));
        $this->assertFalse($items->contains('key', 'b2b_drivers'));
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'drivers', 'store_id' => $store]))
            ->assertForbidden();

        $restaurant = $this->retailStore('RESTAURANT', 'RESTAURANT');
        $manager = $this->retailManager($restaurant, 'restaurant-p262@example.test');
        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'drivers', 'store_id' => $restaurant]))
            ->assertNotFound();
    }

    private function retailStore(string $code, string $profile = 'RETAIL_STORE'): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => $profile,
            'code' => 'V2-P262-'.$code,
            'name' => 'V2 P262 '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailManager(int $storeId, string $email = 'retail-p262@example.test'): User
    {
        $user = $this->user('Retail P262', $email);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function driver(string $name, string $email, int $storeId): void
    {
        $user = $this->user($name, $email);
        DB::table('drivers')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_active' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
