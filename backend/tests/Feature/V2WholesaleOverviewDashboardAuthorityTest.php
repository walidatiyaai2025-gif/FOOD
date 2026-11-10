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

final class V2WholesaleOverviewDashboardAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_overview_link_reuses_real_dashboard_with_both_locales(): void
    {
        $wholesale = $this->globalUser('B2B_ADMIN');

        foreach ([
            'ar' => ['direction' => 'rtl', 'label' => 'إدارة الجملة'],
            'en' => ['direction' => 'ltr', 'label' => 'B2B Wholesale'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();

            $navigation = app(AdminNavigation::class);
            $this->assertContains('b2b', array_keys($navigation->for($wholesale->fresh())));

            $overview = collect($navigation->groupsFor($wholesale->fresh()))
                ->firstWhere('key', 'overview');
            $this->assertIsArray($overview);

            $dashboard = collect($overview['children'])->firstWhere('key', 'b2b_dashboard');
            $this->assertIsArray($dashboard, 'Wholesale dashboard must be discoverable in Overview.');
            $this->assertSame('admin.b2b.module', $dashboard['route']);
            $this->assertSame(['module' => 'dashboard'], $dashboard['params']);
            $this->assertSame('admin.channels.b2b', $dashboard['label']);
            $this->assertSame($expected['label'], $dashboard['label_text'] ?? __($dashboard['label']));
            $this->assertSame(route('admin.b2b.module', $dashboard['params']), route('admin.b2b.dashboard'));

            $this->actingAs($wholesale->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertViewHas('navContext', 'b2b_dashboard')
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['label']);
        }
    }

    public function test_retail_store_admin_cannot_access_wholesale_dashboard_or_foreign_store(): void
    {
        $owned = $this->retailStore('V2-2511-RETAIL-OWN');
        $foreign = $this->retailStore('V2-2511-RETAIL-FOREIGN');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $owned,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $navigation = app(AdminNavigation::class);
            $this->assertSame(['b2c'], array_keys($navigation->for($retail->fresh())));
            $items = collect($navigation->groupsFor($retail->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key');
            $this->assertNotContains('b2b_dashboard', $items);
            $this->assertContains('b2c_dashboard', $items);

            $this->actingAs($retail->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2b.module', ['module' => 'dashboard']))
                ->assertForbidden();

            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $owned]))
                ->assertOk();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_inactive_and_unprivileged_accounts_cannot_gain_wholesale_dashboard_access(): void
    {
        $unprivileged = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$unprivileged, $inactive] as $user) {
            $this->assertNotContains(
                'b2b_dashboard',
                collect(app(AdminNavigation::class)->groupsFor($user->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key'),
            );
            $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
        }
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

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
