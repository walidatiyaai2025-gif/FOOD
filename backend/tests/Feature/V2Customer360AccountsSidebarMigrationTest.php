<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T356R3: Customer 360 is the existing Accounts destination, not a second
 * customer page, permission grant, or cross-channel shortcut.
 */
final class V2Customer360AccountsSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_registered_customer_360_uses_accounts_and_one_canonical_route_in_both_locales(): void
    {
        $registered = app(AdminSidebarManager::class)->registry()['items']['customer_360'];
        $this->assertSame('accounts', $registered['group']);
        $this->assertSame('admin.customer_360', $registered['label']);
        $this->assertSame('both', $registered['scope']);
        $this->assertSame('any', $registered['mode']);
        $this->assertSame(['customers.view', 'b2b.accounts.view'], $registered['permissions']);
        $this->assertTrue(Route::has('admin.customer-360.index'));

        $actor = $this->actor('B2B_ACCOUNT_MANAGER');
        $wholesale = $this->store('B2B', 'T356R3-WHOLESALE');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $matches = $this->customer360Locations($actor->fresh());
            $this->assertCount(1, $matches, 'Customer 360 must be discoverable only once');
            $this->assertSame('accounts', $matches[0]['group']);
            $this->assertSame('admin.customer-360.index', $matches[0]['item']['route']);
            $this->assertSame('admin.customer_360', $matches[0]['item']['label']);
            $this->assertSame([], $matches[0]['item']['params']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.index', [], false))
                ->assertOk()
                ->assertViewIs('admin.customer-360-index')
                ->assertViewHas('navContext', 'customer_360')
                ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2b'
                    && in_array($wholesale, $access['b2b_store_ids'], true)
                    && $access['b2c_store_ids'] === [])
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.customer_360'));
        }
    }

    public function test_retail_accounts_navigation_uses_same_customer_360_key_but_never_crosses_store_scope(): void
    {
        $ownedStore = $this->store('B2C', 'T356R3-RETAIL-OWN');
        $foreignStore = $this->store('B2C', 'T356R3-RETAIL-OTHER');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matches = $this->customer360Locations($actor->fresh());
        $this->assertCount(1, $matches);
        $this->assertSame('accounts', $matches[0]['group']);
        $this->assertSame('admin.customer-360.index', $matches[0]['item']['route']);

        $this->actingAs($actor)
            ->get(route('admin.customer-360.index', [], false))
            ->assertOk()
            ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2c'
                && in_array($ownedStore, $access['b2c_store_ids'], true)
                && ! in_array($foreignStore, $access['b2c_store_ids'], true)
                && $access['b2b_store_ids'] === []);

        $this->actingAs($actor)
            ->get(route('admin.customer-360.index', ['origin_store_id' => $foreignStore], false))
            ->assertNotFound();
    }

    public function test_cosmetic_visible_flag_cannot_create_an_accounts_route_for_unauthorized_actor(): void
    {
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        // The saved preference belongs to a different role; it is not an
        // assignment, an effective grant, or a server-side access decision.
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'customer_360',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->customer360Locations($actor));
        $this->actingAs($actor)
            ->get(route('admin.customer-360.index', [], false))
            ->assertForbidden();

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'customer_360',
            'is_visible' => true,
        ]);
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function store(string $type, string $code): int
    {
        $row = [
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($type === 'B2C') {
            $row['business_profile'] = StoreBusinessProfile::RETAIL_STORE->value;
        }

        return (int) DB::table('stores')->insertGetId($row);
    }

    /**
     * @return list<array{group:string,item:array<string,mixed>}>
     */
    private function customer360Locations(User $actor): array
    {
        app()->forgetInstance(AdminNavigation::class);
        $matches = [];

        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'customer_360') {
                    $matches[] = ['group' => $group['key'], 'item' => $item];
                }
                foreach ($item['children'] ?? [] as $child) {
                    if ($child['key'] === 'customer_360') {
                        $matches[] = ['group' => $group['key'], 'item' => $child];
                    }
                }
            }
        }

        return $matches;
    }
}
