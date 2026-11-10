<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2OverviewSidebarGroupContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_overview_is_an_ordered_authorized_group_in_both_locales(): void
    {
        foreach (['ar' => 'نظرة عامة', 'en' => 'Overview'] as $locale => $expectedLabel) {
            app()->setLocale($locale);
            $user = $this->userWithGlobalRole('B2B_ADMIN', "overview-{$locale}@example.test");

            $groups = app(AdminNavigation::class)->groupsFor($user);
            $this->assertSame('overview', $groups[0]['key']);
            $overview = collect($groups)->firstWhere('key', 'overview');

            $this->assertSame('admin.nav_groups.overview', $overview['label']);
            $this->assertSame($expectedLabel, __($overview['label']));
            $this->assertNotEmpty($overview['children']);
            $this->assertSame(
                ['enterprise_action_center', 'enterprise_workspaces'],
                array_slice(array_column($overview['children'], 'key'), 0, 2),
            );

            foreach ($overview['children'] as $item) {
                $uri = route($item['route'], $item['params'], false);
                $status = $this->actingAs($user)->get($uri)->getStatusCode();
                $this->assertGreaterThanOrEqual(200, $status, "{$item['key']} must be reachable");
                $this->assertLessThan(400, $status, "{$item['key']} must not lead to an inaccessible route");
            }

            foreach ($groups as $group) {
                $this->assertNotEmpty($group['children'], "{$group['key']} must not be an empty menu");
            }
        }
    }

    public function test_retail_overview_is_store_scoped_without_platform_or_wholesale_links(): void
    {
        $ownStore = $this->retailStore('V2-OVERVIEW-OWN');
        $otherStore = $this->retailStore('V2-OVERVIEW-FOREIGN');
        $user = User::query()->create([
            'name' => 'Scoped Retail Overview',
            'email' => 'overview-retail@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'ar',
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $ownStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $overview = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'overview');
        $this->assertIsArray($overview);
        $items = collect($overview['children'])->keyBy('key');

        $this->assertTrue($items->has('enterprise_action_center'));
        $this->assertTrue($items->has('b2c_dashboard'));
        $this->assertFalse($items->has('enterprise_workspaces'));
        $this->assertFalse($items->has('b2b_dashboard'));
        $this->assertFalse($items->has('notifications_center'));
        $this->assertSame((string) $ownStore, $items->get('b2c_dashboard')['params']['store_id']);

        $this->actingAs($user)->get(route('admin.action-center.index'))->assertOk();
        // EnterpriseWorkspaceComposer deliberately grants limited store-scoped
        // direct workspace reads; hiding its platform Sidebar entry is not a
        // blanket 403 policy. Assert the privileged card stays inaccessible.
        $this->actingAs($user)
            ->get(route('admin.enterprise-workspaces.index'))
            ->assertOk()
            ->assertViewHas('workspace', static fn (array $workspace): bool => ! in_array(
                'system_health',
                array_column($workspace['cards'], 'key'),
                true,
            ));
        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $otherStore]))
            ->assertForbidden();
    }

    public function test_inactive_and_roleless_accounts_cannot_create_an_overview_group_or_open_my_work(): void
    {
        $inactive = $this->userWithGlobalRole('B2B_ADMIN', 'overview-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        $roleless = User::query()->create([
            'name' => 'No Dashboard Role',
            'email' => 'overview-roleless@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);

        foreach ([$inactive, $roleless] as $user) {
            $this->assertFalse(
                collect(app(AdminNavigation::class)->groupsFor($user))->contains('key', 'overview'),
            );
            $this->actingAs($user)->get(route('admin.action-center.index'))->assertForbidden();
        }
    }

    private function userWithGlobalRole(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
