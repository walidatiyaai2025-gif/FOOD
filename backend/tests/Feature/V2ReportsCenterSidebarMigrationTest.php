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

/**
 * Source T356R10: one canonical Reports Center link in Analytics, with
 * the existing route and backend authority unchanged by a display remap.
 */
final class V2ReportsCenterSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_reports_center_is_single_authorized_analytics_link_in_arabic_and_english(): void
    {
        $store = $this->retailStore('V2-2727-REPORTS');
        $admin = $this->globalAdmin();

        foreach ([
            'ar' => ['title' => 'مركز التقارير', 'direction' => 'rtl'],
            'en' => ['title' => 'Reports Center', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($admin);
            $reportItems = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'reports_center')
                ->values();

            $this->assertCount(1, $reportItems, 'The existing Reports Center may not be duplicated.');
            $analytics = collect($groups)->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);
            $this->assertContains('reports_center', array_column($analytics['children'], 'key'));

            $item = $reportItems->first();
            $this->assertSame('reports.title', $item['label']);
            $this->assertSame('admin.reports.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertSame('reports.view', $item['permission']);
            $this->assertSame($expected['title'], __('reports.title'));
            $this->assertSame($expected['title'], $item['label_text'] ?? __($item['label']));

            $this->actingAs($admin)
                ->get(route('admin.reports.index', [
                    'report' => 'orders',
                    'store_id' => $store,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['title'])
                ->assertSee('href="'.route('admin.reports.index').'"', false);
        }
    }

    public function test_report_links_never_grant_permissions_or_foreign_retail_store_access(): void
    {
        $ownedStore = $this->retailStore('V2-2727-OWNED');
        $foreignStore = $this->retailStore('V2-2727-FOREIGN');

        $retail = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $noRole = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        foreach (['ar', 'en'] as $locale) {
            $retail->forceFill(['locale' => $locale])->save();
            $noRole->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $retailItems = collect(app(AdminNavigation::class)->groupsFor($retail))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            // Store-scoped reporting retains its existing Retail module link;
            // a translated global Reports Center title is not an RBAC grant.
            $this->assertContains('b2c_reports', $retailItems);
            $this->assertNotContains('reports_center', $retailItems);

            $this->actingAs($retail)
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $ownedStore,
                ]))
                ->assertOk()
                ->assertViewHas('storeId', $ownedStore);

            $this->actingAs($retail)
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $foreignStore,
                ]))
                ->assertNotFound();

            $noRoleKeys = collect(app(AdminNavigation::class)->groupsFor($noRole))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('reports_center', $noRoleKeys);

            $this->actingAs($noRole)
                ->get(route('admin.reports.index'))
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

    private function globalAdmin(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
