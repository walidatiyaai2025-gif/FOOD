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

class V2RetailCrossStoreNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sidebar_links_select_the_store_that_owns_each_permission_instead_of_the_first_assigned_store(): void
    {
        $storeTwo = $this->retailStore('V2-STORE-2');
        $storeThree = $this->retailStore('V2-STORE-3');
        $foreignStore = $this->retailStore('V2-FOREIGN-STORE');
        $user = $this->user();

        $this->assign($user, $storeTwo, 'RETAIL_OPERATIONS');
        $this->assign($user, $storeThree, 'RETAIL_INVENTORY');

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $items = collect(app(AdminNavigation::class)->groupsFor($user))
                ->flatMap(fn (array $group) => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('b2c_inventory'));
            $this->assertTrue($items->has('b2c_products'));
            $this->assertTrue($items->has('b2c_reports'));

            $this->assertSame(
                ['module' => 'inventory', 'store_id' => $storeThree],
                $items->get('b2c_inventory')['params'],
            );
            $this->assertSame(
                ['module' => 'products', 'store_id' => $storeThree],
                $items->get('b2c_products')['params'],
            );
            $this->assertSame(
                ['module' => 'reports', 'store_id' => $storeTwo],
                $items->get('b2c_reports')['params'],
            );

            foreach (['b2c_inventory' => $storeThree, 'b2c_products' => $storeThree, 'b2c_reports' => $storeTwo] as $key => $storeId) {
                $item = $items->get($key);
                $this->actingAs($user)
                    ->get(route($item['route'], $item['params']))
                    ->assertOk()
                    ->assertViewHas('storeId', $storeId)
                    ->assertViewHas('supportAccess', false);
            }

            $this->assertNotContains($foreignStore, array_map(
                static fn (array $item): ?int => $item['params']['store_id'] ?? null,
                $items->values()->all(),
            ));
        }
    }

    public function test_retail_role_in_store_two_cannot_open_store_three_or_cross_channel_urls(): void
    {
        $storeTwo = $this->retailStore('V2-OWNED-STORE-2');
        $storeThree = $this->retailStore('V2-OTHER-STORE-3');
        $user = $this->user();
        $this->assign($user, $storeTwo, 'B2C_STORE_ADMIN');

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeTwo]))
            ->assertOk()
            ->assertViewHas('storeId', $storeTwo);

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeThree]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store' => $storeThree]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/admin/catalog?tab=products&store_id='.$storeThree)
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.b2b.module', ['module' => 'products']))
            ->assertForbidden();
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

    private function user(): User
    {
        return User::query()->create([
            'name' => 'V2 Scoped Retail Worker',
            'email' => 'v2-retail-2795@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function assign(User $user, int $storeId, string $roleCode): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
