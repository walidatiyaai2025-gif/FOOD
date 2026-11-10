<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P184: Executive Dashboard in Overview must be a real authorized route,
 * without introducing a second screen or a Sidebar-only access decision.
 */
final class V2ExecutiveDashboardOverviewAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_executive_dashboard_is_discoverable_and_opens_for_arabic_and_english(): void
    {
        $actor = $this->actorWithGlobalRole('B2B_ADMIN', 'v2-p184-wholesale@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $overview = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'overview');
            $this->assertIsArray($overview, 'Overview must exist for an authorized wholesale manager.');

            $item = collect($overview['children'])->firstWhere('key', 'b2b_dashboard');
            $this->assertIsArray($item, 'The executive dashboard must be discoverable inside Overview.');
            $this->assertSame('admin.b2b.module', $item['route']);
            $this->assertSame('dashboard', $item['params']['module'] ?? null);
            $this->assertSame('admin.channels.b2b', $item['label']);
            $this->assertFalse(
                collect($overview['children'])->contains('key', 'b2c_dashboard'),
                'Wholesale role cannot receive a Retail executive dashboard shortcut.',
            );

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.channels.b2b', [], $locale));

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard'], false))
                ->assertForbidden();
        }
    }

    public function test_retail_executive_dashboard_remains_store_scoped_and_never_opens_wholesale_dashboard(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P184-RETAIL',
            'name' => 'P184 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = User::query()->create([
            'name' => 'Retail Dashboard Manager',
            'email' => 'v2-p184-retail@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $overview = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'overview');
        $this->assertIsArray($overview);
        $this->assertTrue(collect($overview['children'])->contains('key', 'b2c_dashboard'));
        $this->assertFalse(collect($overview['children'])->contains('key', 'b2b_dashboard'));

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $storeId], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.b2b.module', ['module' => 'dashboard'], false))
            ->assertForbidden();
    }

    public function test_roleless_and_inactive_accounts_cannot_open_the_executive_dashboard_directly(): void
    {
        $roleless = User::query()->create([
            'name' => 'Roleless',
            'email' => 'v2-p184-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $inactive = $this->actorWithGlobalRole('B2B_ADMIN', 'v2-p184-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $overview = collect($groups)->firstWhere('key', 'overview');

            $this->assertFalse(
                collect($overview['children'] ?? [])->contains('key', 'b2b_dashboard'),
                'An ineligible actor must not be shown a valid executive dashboard route.',
            );
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'dashboard'], false))
                ->assertForbidden();
        }
    }

    private function actorWithGlobalRole(string $code, string $email): User
    {
        $user = User::query()->create([
            'name' => $code,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
