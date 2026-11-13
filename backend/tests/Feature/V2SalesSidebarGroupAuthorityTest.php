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

final class V2SalesSidebarGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p192_group_owns_only_authorized_ready_quotation_destination_in_ar_and_en(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.sales', $registry['groups']['sales']['label']);
        $this->assertSame('sales', $registry['items']['sales_quotations']['group']);
        $this->assertSame('sales', app(AdminSidebarManager::class)->layoutData()['items']['sales_quotations']['group_key']);
        $this->assertSame(['orders.view'], $registry['items']['sales_quotations']['permissions']);

        $own = $this->store('V2-P192-OWN');
        $foreign = $this->store('V2-P192-FOREIGN');
        $retail = $this->retailActor($own);
        $groups = collect(app(AdminNavigation::class)->groupsFor($retail));
        $sales = $groups->firstWhere('key', 'sales');
        $this->assertIsArray($sales);
        $this->assertArrayNotHasKey('route', $sales);
        $this->assertSame(['sales_quotations'], collect($sales['children'])->pluck('key')->all());
        $this->assertSame('admin.sales-quotations.index', $sales['children'][0]['route']);
        $this->assertFalse(collect($groups->firstWhere('key', 'accounts')['children'])->contains('key', 'sales_quotations'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $this->actingAs($retail->fresh())->get(route('admin.sales-quotations.index', ['store_id' => $own]))
                ->assertOk()->assertSee('dir="'.$direction.'"', false)->assertSee(__('admin.nav_groups.sales'));
        }
        $this->actingAs($retail)->get(route('admin.sales-quotations.index', ['store_id' => $foreign]))->assertNotFound();
    }

    public function test_wholesale_and_denied_roles_keep_exact_store_and_http_authority(): void
    {
        $b2b = app(WholesalePrincipal::class)->storeId();
        $b2c = $this->store('V2-P192-RETAIL');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $sales = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'sales');
        $this->assertIsArray($sales);
        $this->actingAs($actor)->get(route('admin.sales-quotations.index', ['store_id' => $b2b]))->assertOk();
        $this->actingAs($actor)->get(route('admin.sales-quotations.index', ['store_id' => $b2c]))->assertNotFound();

        $denied = User::factory()->create(['is_active' => true]);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($denied))->contains('key', 'sales'));
        $this->actingAs($denied)->get(route('admin.sales-quotations.index', ['store_id' => $b2c]))->assertForbidden();
    }

    public function test_sidebar_group_hiding_never_changes_real_quotation_authorization(): void
    {
        $store = $this->store('V2-P192-HIDDEN');
        $actor = $this->retailActor($store);
        DB::table('admin_sidebar_groups')->updateOrInsert(['group_key' => 'sales'], [
            'label_ar' => null, 'label_en' => null, 'icon' => '▤', 'sort_order' => 52,
            'is_enabled' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor))->contains('key', 'sales'));
        $this->actingAs($actor)->get(route('admin.sales-quotations.index', ['store_id' => $store]))->assertOk();

        DB::table('admin_sidebar_groups')->where('group_key', 'sales')->update(['is_enabled' => true]);
        Role::query()->where('code', 'B2C_STORE_ADMIN')->update(['is_active' => false]);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))->contains('key', 'sales'));
        $this->actingAs($actor->fresh())->get(route('admin.sales-quotations.index', ['store_id' => $store]))->assertForbidden();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE', 'code' => $code, 'name' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function retailActor(int $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $actor->fresh();
    }
}
