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

final class V2OverviewMultiChannelDashboardAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_mixed_role_overview_exposes_both_real_dashboards_without_crossing_store_scope(): void
    {
        $ownedStore = $this->retailStore('V2-2506-MIXED-OWNED');
        $foreignStore = $this->retailStore('V2-2506-MIXED-FOREIGN');
        $inactiveStore = $this->retailStore('V2-2506-MIXED-INACTIVE');
        DB::table('stores')->where('id', $inactiveStore)->update(['is_active' => false]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $actor->storeRoleAssignments()->create([
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);

        foreach ([
            'ar' => ['direction' => 'rtl', 'wholesale' => 'إدارة الجملة', 'retail' => 'لوحة التحكم'],
            'en' => ['direction' => 'ltr', 'wholesale' => 'B2B Wholesale', 'retail' => 'Dashboard'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $navigation = app(AdminNavigation::class);
            $this->assertSame(['b2b', 'b2c'], array_keys($navigation->for($actor->fresh())));

            $overview = collect($navigation->groupsFor($actor->fresh()))
                ->firstWhere('key', 'overview');
            $this->assertIsArray($overview);
            $links = collect($overview['children']);

            $this->assertCount(1, $links->where('key', 'b2b_dashboard'));
            $this->assertCount(1, $links->where('key', 'b2c_dashboard'));
            $wholesale = $links->firstWhere('key', 'b2b_dashboard');
            $retail = $links->firstWhere('key', 'b2c_dashboard');

            $this->assertSame('admin.b2b.module', $wholesale['route']);
            $this->assertSame(['module' => 'dashboard'], $wholesale['params']);
            $this->assertSame($expected['wholesale'], $wholesale['label_text'] ?? __($wholesale['label']));
            $this->assertSame('admin.b2c.module', $retail['route']);
            $this->assertSame(
                ['module' => 'dashboard', 'store_id' => (string) $ownedStore],
                $retail['params'],
            );
            $this->assertSame($expected['retail'], $retail['label_text'] ?? __($retail['label']));

            $this->actingAs($actor->fresh())
                ->get(route($wholesale['route'], $wholesale['params']))
                ->assertOk()
                ->assertViewHas('navContext', 'b2b_dashboard')
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['wholesale'])
                ->assertSee($expected['retail']);

            $this->actingAs($actor->fresh())
                ->get(route($retail['route'], $retail['params']))
                ->assertOk()
                ->assertViewHas('storeId', $ownedStore)
                ->assertViewHas('navContext', 'b2c_dashboard')
                ->assertViewHas('supportAccess', false)
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['wholesale'])
                ->assertSee($expected['retail']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStore]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $inactiveStore]))
                ->assertNotFound();
        }
    }

    public function test_an_overview_label_does_not_authorize_missing_roles(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $unprivileged->forceFill(['locale' => $locale])->save();

            $groups = collect(app(AdminNavigation::class)->groupsFor($unprivileged->fresh()));
            $dashboardKeys = $groups->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key');
            $this->assertNotContains('b2b_dashboard', $dashboardKeys);
            $this->assertNotContains('b2c_dashboard', $dashboardKeys);

            $this->actingAs($unprivileged->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
            $this->actingAs($unprivileged->fresh())
                ->get(route('admin.b2c.dashboard'))
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
}
