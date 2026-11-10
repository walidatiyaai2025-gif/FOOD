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

final class V2SidebarHierarchyDisclosureCuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_group_disclosures_are_visible_localized_and_native_in_both_directions(): void
    {
        $user = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->getContent();

            $count = count(app(AdminNavigation::class)->groupsFor($user->fresh()));
            $this->assertGreaterThan(0, $count);
            $this->assertSame($count, substr_count($html, 'class="foodex-nav-disclosure"'));
            $this->assertSame($count, substr_count($html, 'class="foodex-nav-chevron"'));
            $this->assertSame($count, substr_count($html, 'class="foodex-nav-disclosure-expand"'));
            $this->assertSame($count, substr_count($html, 'class="foodex-nav-disclosure-collapse"'));
            $this->assertStringContainsString(__('sidebar_admin.expand_group'), $html);
            $this->assertStringContainsString(__('sidebar_admin.collapse_group'), $html);
            $this->assertStringContainsString('summary:focus-visible', $html);
            $this->assertStringContainsString('summary{list-style:none;cursor:pointer;min-height:46px', $html);
            $this->assertStringContainsString('details class="foodex-nav-group', $html);
            $this->assertStringContainsString('foodex-nav-subchildren', $html);
        }
    }

    public function test_disclosure_cues_do_not_add_wholesale_access_for_retail_store_users(): void
    {
        $storeId = $this->retailStore('V2-P371-OWN');
        $foreignStoreId = $this->retailStore('V2-P371-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $storeId]))
                ->assertOk()
                ->getContent();

            $expectedGroups = app(AdminNavigation::class)->groupsFor($user->fresh());
            $this->assertSame(count($expectedGroups), substr_count($html, 'class="foodex-nav-disclosure"'));
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStoreId]))
                ->assertNotFound();
        }
    }

    public function test_inactive_and_roleless_users_cannot_gain_dashboard_groups_from_disclosure_markup(): void
    {
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$inactive, $roleless] as $user) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($user->fresh()));
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
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
