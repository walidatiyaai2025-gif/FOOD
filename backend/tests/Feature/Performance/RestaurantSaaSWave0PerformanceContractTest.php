<?php

namespace Tests\Feature\Performance;

use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RestaurantSaaSWave0PerformanceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('smart_ordering.enterprise_entitlement_bridge', false);
    }

    public function test_disabled_enterprise_bridge_adds_zero_enterprise_queries_to_repeated_runtime_checks(): void
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', 'B2C')
            ->value('id');

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'RS-W0-PERF',
            'name' => 'Restaurant SaaS Wave 0 Performance',
            'is_active' => true,
        ]);

        $admin = User::query()->create([
            'name' => 'Restaurant SaaS Performance Admin',
            'email' => 'restaurant-saas-wave0-performance@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $roleId = (int) Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $store->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = app(SmartOrderingSiteService::class)->ensureForStore(
            $store->id,
            $admin,
        );
        app(SmartOrderingSiteService::class)->setEntitlement(
            $admin,
            $site,
            Module::PUBLIC_MENU,
            true,
        );

        $enterpriseQueries = 0;
        DB::listen(static function ($query) use (&$enterpriseQueries): void {
            if (str_contains((string) $query->sql, 'enterprise_')) {
                $enterpriseQueries++;
            }
        });

        $policy = app(SmartOrderingRuntimePolicy::class);

        for ($iteration = 0; $iteration < 20; $iteration++) {
            $this->assertTrue($policy->entitled($site, Module::PUBLIC_MENU));
        }

        $this->assertSame(
            0,
            $enterpriseQueries,
            'A disabled Restaurant SaaS Enterprise bridge must add zero Enterprise queries.',
        );
    }
}
