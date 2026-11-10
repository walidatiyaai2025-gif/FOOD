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
 * T037R04: explicit user x role x store x channel x locale authorization matrix.
 * Sidebar presence is checked against real authenticated HTTP destinations.
 */
final class V2UserRoleStoreHttpMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_store_channel_matrix_matches_navigation_and_direct_http_in_both_locales(): void
    {
        $storeA = $this->retailStore('V2-2790-A');
        $storeB = $this->retailStore('V2-2790-B');

        $wholesale = $this->user('wholesale');
        $this->assignGlobalRole($wholesale, 'B2B_ADMIN');

        $retailA = $this->user('retail-a');
        $this->assignRetailRole($retailA, $storeA, 'RETAIL_INVENTORY');

        $retailB = $this->user('retail-b');
        $this->assignRetailRole($retailB, $storeB, 'RETAIL_INVENTORY');

        $mixed = $this->user('mixed');
        $this->assignGlobalRole($mixed, 'B2B_ADMIN');
        $this->assignRetailRole($mixed, $storeA, 'RETAIL_INVENTORY');

        // The expected HTTP result is based on role and store membership,
        // never on the link's visibility or the current UI language.
        $matrix = [
            [
                'actor' => 'wholesale-only',
                'user' => $wholesale,
                'channels' => ['b2b'],
                'wholesale' => true,
                'inventory_store' => null,
                'store_a' => false,
                'store_b' => false,
            ],
            [
                'actor' => 'retail-store-a',
                'user' => $retailA,
                'channels' => ['b2c'],
                'wholesale' => false,
                'inventory_store' => $storeA,
                'store_a' => true,
                'store_b' => false,
            ],
            [
                'actor' => 'retail-store-b',
                'user' => $retailB,
                'channels' => ['b2c'],
                'wholesale' => false,
                'inventory_store' => $storeB,
                'store_a' => false,
                'store_b' => true,
            ],
            [
                'actor' => 'mixed-wholesale-retail-a',
                'user' => $mixed,
                'channels' => ['b2b', 'b2c'],
                'wholesale' => true,
                'inventory_store' => $storeA,
                'store_a' => true,
                'store_b' => false,
            ],
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($matrix as $case) {
                /** @var User $user */
                $user = $case['user'];
                $user->forceFill(['locale' => $locale])->save();

                $channels = app(AdminNavigation::class)->for($user);
                $this->assertSame($case['channels'], array_keys($channels), $case['actor'].': channel projection');

                $items = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->keyBy('key');

                $this->assertSame($case['wholesale'], $items->has('b2b_products'), $case['actor'].': wholesale link');
                $this->assertSame($case['inventory_store'] !== null, $items->has('b2c_inventory'), $case['actor'].': retail link');
                $this->assertFalse($items->has('sidebar_role_access'), $case['actor'].': unauthorized role management');
                $this->assertFalse($items->has('platform_owner_modules'), $case['actor'].': unauthorized platform module management');

                if ($case['inventory_store'] !== null) {
                    $this->assertSame(
                        ['module' => 'inventory', 'store_id' => (string) $case['inventory_store']],
                        $items->get('b2c_inventory')['params'],
                        $case['actor'].': inventory link must resolve to an assigned store',
                    );
                }

                $b2bResponse = $this->actingAs($user)
                    ->get(route('admin.b2b.module', ['module' => 'products']));

                if ($case['wholesale']) {
                    $b2bResponse->assertOk()->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
                } else {
                    $b2bResponse->assertForbidden();
                }

                foreach ([$storeA => 'store_a', $storeB => 'store_b'] as $storeId => $expectedColumn) {
                    $response = $this->actingAs($user)
                        ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeId]));

                    if ($case[$expectedColumn]) {
                        $response->assertOk()
                            ->assertViewHas('storeId', $storeId)
                            ->assertViewHas('supportAccess', false);
                    } else {
                        // 403 rejects a channel/permission; 404 also conceals a
                        // foreign store. Neither may accidentally render 200/302.
                        $this->assertContains(
                            $response->getStatusCode(),
                            [403, 404],
                            $case['actor'].': foreign or unauthorized '.$expectedColumn,
                        );
                    }
                }
            }
        }
    }

    private function user(string $actor): User
    {
        return User::factory()->create([
            'name' => 'V2 T037R04 '.$actor,
            'email' => 'v2-2790-'.$actor.'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
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

    private function assignGlobalRole(User $user, string $roleCode): void
    {
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
    }

    private function assignRetailRole(User $user, int $storeId, string $roleCode): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
