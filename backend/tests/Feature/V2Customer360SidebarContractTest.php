<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P187: the existing Customer 360 Sidebar destination must have the same
 * backend permissions and tenant/store boundaries as its real HTTP route.
 * Visibility is never an authorization grant.
 */
final class V2Customer360SidebarContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_account_manager_gets_one_registered_customer_360_destination_in_arabic_and_english(): void
    {
        $actor = $this->globalUser('B2B_ACCOUNT_MANAGER');
        $this->store('B2B', 'P187-WHOLESALE', 'P187 Wholesale');

        $this->assertTrue(Route::has('admin.customer-360.index'));
        $this->assertTrue($actor->hasPermission('b2b.accounts.view'));
        $this->assertTrue($actor->hasPermission('customers.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $navigation = $this->customer360Item($actor->fresh());
            $this->assertNotNull($navigation);
            $this->assertSame('admin.customer-360.index', $navigation['route']);
            $this->assertSame([], $navigation['params']);
            $this->assertSame('admin.customer_360', $navigation['label']);

            $this->actingAs($actor->fresh())
                ->get(route($navigation['route'], $navigation['params']))
                ->assertOk()
                ->assertViewIs('admin.customer-360-index')
                ->assertViewHas('navContext', 'customer_360')
                ->assertViewHas('access', function (array $access): bool {
                    return $access['mode'] === 'b2b'
                        && $access['b2c_store_ids'] === [];
                })
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.customer_360'));
        }
    }

    public function test_retail_customer_360_is_scoped_to_owned_store_and_rejects_foreign_store_and_customer_urls(): void
    {
        $ownedStore = $this->store('B2C', 'P187-RETAIL-A', 'P187 Owned Store');
        $foreignStore = $this->store('B2C', 'P187-RETAIL-B', 'P187 Foreign Store');
        $ownedCustomer = $this->customer('P187 Owned Customer', 'p187-owned@example.test', $ownedStore);
        $foreignCustomer = $this->customer('P187 Foreign Customer', 'p187-foreign@example.test', $foreignStore);
        $ownedPlatform = PlatformCustomer::query()->where('user_id', $ownedCustomer->id)->firstOrFail();
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreignCustomer->id)->firstOrFail();
        $actor = $this->retailAdmin($ownedStore);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $navigation = $this->customer360Item($actor->fresh());
            $this->assertNotNull($navigation);
            $this->assertSame('admin.customer-360.index', $navigation['route']);

            $this->actingAs($actor->fresh())
                ->get(route($navigation['route']))
                ->assertOk()
                ->assertViewHas('access', function (array $access) use ($ownedStore, $foreignStore): bool {
                    return $access['mode'] === 'b2c'
                        && in_array($ownedStore, $access['b2c_store_ids'], true)
                        && ! in_array($foreignStore, $access['b2c_store_ids'], true)
                        && $access['b2b_store_ids'] === [];
                })
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('p187-owned@example.test')
                ->assertDontSee('p187-foreign@example.test');

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', ['platformCustomer' => $ownedPlatform->id]))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', ['platformCustomer' => $foreignPlatform->id]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.index', ['origin_store_id' => $foreignStore]))
                ->assertNotFound();
        }
    }

    public function test_roleless_user_cannot_gain_customer_360_access_from_sidebar_or_direct_http(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNull($this->customer360Item($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.index'))
                ->assertForbidden();
        }
    }

    /** @return array<string, mixed>|null */
    private function customer360Item(User $actor): ?array
    {
        app()->forgetInstance(AdminNavigation::class);
        $matches = [];

        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'customer_360') {
                    $this->assertSame('accounts', $group['key']);
                    $matches[] = $item;
                }
            }
        }

        $this->assertLessThanOrEqual(1, count($matches), 'Customer 360 must have one canonical Sidebar destination');

        return $matches[0] ?? null;
    }

    private function globalUser(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function customer(string $name, string $email, int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => $name,
            'email' => $email,
            'phone' => '+201000000000',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        $attributes = [
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($type === 'B2C') {
            $attributes['business_profile'] = StoreBusinessProfile::RETAIL_STORE->value;
        }

        return (int) DB::table('stores')->insertGetId($attributes);
    }
}
