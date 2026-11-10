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

final class V2ChannelSpecificSidebarLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_and_retail_product_labels_do_not_mix_when_user_has_both_channels(): void
    {
        $ownedStore = $this->retailStore('V2-2880-OWNED');
        $otherStore = $this->retailStore('V2-2880-OTHER');

        $mixed = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $mixed->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $this->assignRetailInventory($mixed, $ownedStore);

        $retailOnly = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->assignRetailInventory($retailOnly, $ownedStore);

        foreach ([
            'ar' => ['wholesale' => 'إدارة منتجات الجملة', 'retail' => 'منتجات التجزئة', 'inventory' => 'مخزون التجزئة', 'direction' => 'rtl'],
            'en' => ['wholesale' => 'Wholesale Product Management', 'retail' => 'Retail Products', 'inventory' => 'Retail Inventory', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $mixed->forceFill(['locale' => $locale])->save();
            $retailOnly->forceFill(['locale' => $locale])->save();

            $this->assertSame(
                ['b2b', 'b2c'],
                array_keys(app(AdminNavigation::class)->for($mixed)),
                'Mixed user must retain both legitimate channels.',
            );

            $items = collect(app(AdminNavigation::class)->groupsFor($mixed))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            foreach ([
                'b2b_products' => ['label' => $expected['wholesale'], 'channel' => 'b2b'],
                'b2c_products' => ['label' => $expected['retail'], 'channel' => 'b2c'],
                'b2c_inventory' => ['label' => $expected['inventory'], 'channel' => 'b2c'],
            ] as $key => $assertions) {
                $this->assertTrue($items->has($key), 'Expected authorized Sidebar destination: '.$key);
                $node = $items->get($key);
                $this->assertSame($assertions['label'], $node['label_text'] ?? __($node['label']));
                $this->assertSame('admin.'.$assertions['channel'].'.module', $node['route']);
                $this->assertSame($assertions['channel'] === 'b2b' ? 'b2b' : 'b2c', explode('_', $key)[0]);
            }

            $this->assertNotSame(
                $items->get('b2b_products')['label_text'] ?? __($items->get('b2b_products')['label']),
                $items->get('b2c_products')['label_text'] ?? __($items->get('b2c_products')['label']),
            );
            $this->assertSame(
                ['module' => 'products', 'store_id' => (string) $ownedStore],
                $items->get('b2c_products')['params'],
            );
            $this->assertSame(
                ['module' => 'inventory', 'store_id' => (string) $ownedStore],
                $items->get('b2c_inventory')['params'],
            );

            $this->actingAs($mixed)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['wholesale'])
                ->assertSee($expected['retail'])
                ->assertSee($expected['inventory']);

            $this->actingAs($mixed)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertOk();

            $this->actingAs($mixed)
                ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $ownedStore]))
                ->assertOk()
                ->assertViewHas('storeId', $ownedStore)
                ->assertViewHas('supportAccess', false);

            $this->actingAs($mixed)
                ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $otherStore]))
                ->assertNotFound();

            $retailKeys = collect(app(AdminNavigation::class)->groupsFor($retailOnly))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            $this->assertContains('b2c_products', $retailKeys);
            $this->assertNotContains('b2b_products', $retailKeys);

            $this->actingAs($retailOnly)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertForbidden();

            $this->actingAs($retailOnly)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $ownedStore]))
                ->assertOk()
                ->assertViewHas('storeId', $ownedStore);
        }
    }

    public function test_inactive_and_unprivileged_accounts_cannot_gain_any_channel_from_display_labels(): void
    {
        $unprivileged = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $this->assertFalse($unprivileged->hasPermission('catalog.view'));

        foreach (['ar', 'en'] as $locale) {
            $unprivileged->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $keys = collect(app(AdminNavigation::class)->groupsFor($unprivileged))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            $this->assertNotContains('b2b_products', $keys);
            $this->assertNotContains('b2c_products', $keys);

            $this->actingAs($unprivileged)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertForbidden();
        }
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

    private function assignRetailInventory(User $user, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
