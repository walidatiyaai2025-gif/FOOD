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

final class V2RetailOverviewDashboardAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_overview_uses_existing_authorized_dashboard_and_store_context_in_both_locales(): void
    {
        $ownedStore = $this->retailStore('V2-2512-OWNED');
        $retail = $this->retailAdmin($ownedStore);

        foreach ([
            'ar' => ['direction' => 'rtl', 'label' => 'لوحة التحكم'],
            'en' => ['direction' => 'ltr', 'label' => 'Dashboard'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $navigation = app(AdminNavigation::class);
            $this->assertSame(['b2c'], array_keys($navigation->for($retail->fresh())));
            $overview = collect($navigation->groupsFor($retail->fresh()))
                ->firstWhere('key', 'overview');
            $this->assertIsArray($overview);

            $dashboard = collect($overview['children'])->firstWhere('key', 'b2c_dashboard');
            $this->assertIsArray($dashboard);
            $this->assertSame('admin.b2c.module', $dashboard['route']);
            $this->assertSame(
                ['module' => 'dashboard', 'store_id' => (string) $ownedStore],
                $dashboard['params'],
            );
            $this->assertSame('admin.premium_nav.dashboard', $dashboard['label']);
            $this->assertSame($expected['label'], $dashboard['label_text'] ?? __($dashboard['label']));

            $this->actingAs($retail->fresh())
                ->get(route($dashboard['route'], $dashboard['params']))
                ->assertOk()
                ->assertViewHas('storeId', $ownedStore)
                ->assertViewHas('navContext', 'b2c_dashboard')
                ->assertViewHas('supportAccess', false)
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['label']);
        }
    }

    public function test_retail_dashboard_denies_foreign_and_inactive_stores_and_has_no_b2b_bypass(): void
    {
        $ownedStore = $this->retailStore('V2-2512-OWN');
        $foreignStore = $this->retailStore('V2-2512-FOREIGN');
        $inactiveStore = $this->retailStore('V2-2512-INACTIVE');
        DB::table('stores')->where('id', $inactiveStore)->update(['is_active' => false]);
        $retail = $this->retailAdmin($ownedStore);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.dashboard', ['store_id' => $ownedStore]))
                ->assertOk();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStore]))
                ->assertNotFound();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $inactiveStore]))
                ->assertNotFound();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
        }
    }

    public function test_b2b_only_and_unprivileged_actors_cannot_discover_or_open_retail_dashboard(): void
    {
        $retailStore = $this->retailStore('V2-2512-ROLE-TEST');
        $wholesale = User::factory()->create(['is_active' => true]);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $unprivileged = User::factory()->create(['is_active' => true]);

        foreach ([$wholesale, $unprivileged] as $user) {
            $navigation = app(AdminNavigation::class);
            $this->assertNotContains(
                'b2c_dashboard',
                collect($navigation->groupsFor($user->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key'),
            );

            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $retailStore]))
                ->assertForbidden();
        }
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
