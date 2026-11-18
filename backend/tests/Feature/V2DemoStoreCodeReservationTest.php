<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2DemoStoreCodeReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_admin_cannot_create_a_live_retail_store_with_the_repository_demo_prefix(): void
    {
        $admin = $this->superAdmin();
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $storeCount = DB::table('stores')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach (['FOODEX-DEMO-B2C-SPOOF', 'foodex-demo-b2c-spoof'] as $code) {
            $this->actingAs($admin)
                ->postJson(route('admin.retail-stores.store'), [
                    'business_profile' => 'RETAIL_STORE',
                    'code' => $code,
                    'name' => 'Unauthorized demo-prefix live store',
                    'price_tier_id' => $tierId,
                    'manager_mode' => 'new',
                    'manager_name' => 'Live Store Manager',
                    'manager_email' => 'demo-prefix-manager@example.test',
                    'manager_password' => 'password123',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('code');
        }

        $this->assertSame($storeCount, DB::table('stores')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertFalse(DB::table('stores')
            ->whereRaw('UPPER(code) LIKE ?', ['FOODEX-DEMO-B2C-SPOOF%'])
            ->exists());
    }

    public function test_admin_cannot_rename_a_live_retail_store_into_the_repository_demo_prefix(): void
    {
        $admin = $this->superAdmin();
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-DEMO-PREFIX-LIVE',
            'name' => 'Live Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeCount = DB::table('stores')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($admin)
            ->patchJson(route('admin.retail-stores.update', $storeId), [
                'code' => 'foodex-demo-b2c-renamed-live',
                'name' => 'Renamed Live Retail Store',
                'price_tier_id' => $tierId,
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame($storeCount, DB::table('stores')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'code' => 'V2-DEMO-PREFIX-LIVE',
            'name' => 'Live Retail Store',
        ]);
        $this->assertDatabaseMissing('stores', ['code' => 'FOODEX-DEMO-B2C-RENAMED-LIVE']);
    }

    private function superAdmin(): User
    {
        $admin = User::query()->create([
            'name' => 'Demo Prefix Guard Admin',
            'email' => 'demo-prefix-admin@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin->fresh();
    }
}
