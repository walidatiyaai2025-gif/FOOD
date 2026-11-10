<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P171: shared Customer 360 retains one translated business name while
 * real channel and store authority, not the Sidebar title, scopes every request.
 */
final class V2SharedCustomer360ChannelScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        $this->store('B2B', 'V2-2881-WHOLESALE');
        config()->set('foodex.platform_wholesale_store_code', 'V2-2881-WHOLESALE');
    }

    public function test_one_shared_customer_name_and_route_have_distinct_authorized_channel_scopes_in_both_locales(): void
    {
        $retailA = $this->store('B2C', 'V2-2881-RETAIL-A');
        $retailB = $this->store('B2C', 'V2-2881-RETAIL-B');

        $wholesale = $this->globalUser('B2B_ADMIN', 'wholesale');
        $retail = $this->retailUser($retailA, 'retail');
        $mixed = $this->globalUser('B2B_ADMIN', 'mixed');
        $this->assignRetailRole($mixed, $retailA);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);

            $labels = [];

            foreach ([
                'wholesale' => [$wholesale, ['b2b']],
                'retail' => [$retail, ['b2c']],
                'mixed' => [$mixed, ['b2b', 'b2c']],
            ] as $actor => [$user, $expectedChannels]) {
                $user->forceFill(['locale' => $locale])->save();
                $navigation = app(AdminNavigation::class);
                $this->assertSame($expectedChannels, array_keys($navigation->for($user)), $actor);

                $items = collect($navigation->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children']);

                $shared = $items->where('key', 'customer_360')->values();
                $this->assertCount(1, $shared, $actor.': one canonical shared destination, never duplicate links');
                $item = $shared->first();

                $this->assertSame('admin.customer-360.index', $item['route']);
                $this->assertSame([], $item['params']);
                $this->assertSame('admin.customer_360', $item['label']);
                $labels[$actor] = $item['label_text'] ?? __($item['label']);
                $this->assertNotSame($item['label'], $labels[$actor]);
            }

            $this->assertSame($labels['wholesale'], $labels['retail'], 'Name changes must not imply tenant grants');
            $this->assertSame($labels['retail'], $labels['mixed']);

            $this->actingAs($wholesale)
                ->get(route('admin.customer-360.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2b' && $access['b2c_store_ids'] === []);

            $this->actingAs($retail)
                ->get(route('admin.customer-360.index', ['origin_store_id' => $retailA]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2c'
                    && in_array($retailA, $access['b2c_store_ids'], true)
                    && $access['b2b_store_ids'] === []);

            // A forged origin-store filter cannot cross the shared page's tenant boundary.
            $this->actingAs($retail)
                ->get(route('admin.customer-360.index', ['origin_store_id' => $retailB]))
                ->assertNotFound();

            $this->actingAs($wholesale)
                ->get(route('admin.customer-360.index', ['origin_store_id' => $retailA]))
                ->assertNotFound();

            // With both legitimate roles, the existing canonical Customer 360 route
            // uses its B2B-default view, not an unauthorized B2B+B2C union.
            $this->actingAs($mixed)
                ->get(route('admin.customer-360.index'))
                ->assertOk()
                ->assertViewHas('access', static fn (array $access): bool => $access['mode'] === 'b2b'
                    && $access['b2c_store_ids'] === []);

            $this->actingAs($mixed)
                ->get(route('admin.customer-360.index', ['origin_store_id' => $retailB]))
                ->assertNotFound();
        }
    }

    public function test_same_shared_name_does_not_allow_foreign_customer_read_or_unprivileged_route_access(): void
    {
        $storeA = $this->store('B2C', 'V2-2881-CUSTOMER-A');
        $storeB = $this->store('B2C', 'V2-2881-CUSTOMER-B');

        $customerA = app(PlatformCustomerService::class)->register([
            'name' => 'V2 P171 Customer A',
            'email' => 'v2-p171-customer-a@example.test',
            'phone' => '+201000028810',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeA,
        ]);
        $customerB = app(PlatformCustomerService::class)->register([
            'name' => 'V2 P171 Customer B',
            'email' => 'v2-p171-customer-b@example.test',
            'phone' => '+201000028811',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeB,
        ]);

        $platformA = PlatformCustomer::query()->where('user_id', $customerA->getKey())->firstOrFail();
        $platformB = PlatformCustomer::query()->where('user_id', $customerB->getKey())->firstOrFail();

        $retail = $this->retailUser($storeA, 'reader');
        $unprivileged = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $unprivileged->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $platformA->getKey()]))
                ->assertOk();

            $this->actingAs($retail)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $platformB->getKey()]))
                ->assertNotFound();

            $keys = collect(app(AdminNavigation::class)->groupsFor($unprivileged))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('customer_360', $keys);

            $this->actingAs($unprivileged)
                ->get(route('admin.customer-360.index'))
                ->assertForbidden();
        }
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalUser(string $roleCode, string $name): User
    {
        $user = User::factory()->create([
            'name' => 'V2 P171 '.$name,
            'email' => 'v2-2881-'.$name.'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailUser(int $storeId, string $name): User
    {
        $user = User::factory()->create([
            'email' => 'v2-2881-'.$name.'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->assignRetailRole($user, $storeId);

        return $user;
    }

    private function assignRetailRole(User $user, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
