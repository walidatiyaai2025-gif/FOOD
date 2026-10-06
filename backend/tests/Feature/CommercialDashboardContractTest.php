<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_products_workspace_exposes_server_authoritative_sales_control_contract(): void
    {
        [$manager, $storeId] = $this->retailManager();

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('data-commercial-admin-surface="canonical-commercial-policy"', false)
            ->assertSee('Product Sales Control')
            ->assertSee('evaluate_policy')
            ->assertSee('remaining_quota')
            ->assertSee('PRODUCT_CLOSED')
            ->assertSee('Canonical backend contract pending');
    }

    public function test_promotions_workspace_exposes_flash_contract_without_duplicate_mutation_logic(): void
    {
        [$manager, $storeId] = $this->retailManager();

        $response = $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'promotions', 'store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('data-commercial-admin-surface="canonical-flash-offer"', false)
            ->assertSee('Flash Offers')
            ->assertSee('reserve_flash_offer')
            ->assertSee('release_reservation')
            ->assertSee('FLASH_RESERVATION_EXPIRED')
            ->assertSee('Create/edit/activate mutations are intentionally gated');
    }

    /** @return array{0:User,1:int} */
    private function retailManager(): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'COMMERCIAL-UI',
            'name' => 'Commercial UI Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = User::query()->create([
            'name' => 'Commercial Manager',
            'email' => 'commercial-dashboard@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$manager, $storeId];
    }
}
