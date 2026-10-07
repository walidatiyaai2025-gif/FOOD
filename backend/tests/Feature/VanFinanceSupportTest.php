<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VanFinanceSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_support_van_wallet_collections_receipts_and_remittances(): void
    {
        $admin = $this->superAdmin();
        $now = now();
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'VFS-B2B',
            'name' => 'Van Finance Support Store',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'VAN-SUPPORT-7',
            'plate_number' => 'KUWAIT-7007',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $accountId = (int) DB::table('collection_accounts')->insertGetId([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'store_id' => $storeId,
            'currency' => 'KWD',
            'status' => 'active',
            'custody_limit' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $collectionId = (int) DB::table('collection_transactions')->insertGetId([
            'collection_account_id' => $accountId,
            'payment_id' => null,
            'idempotency_key' => 'VFS-COLLECTION-1',
            'type' => 'collection',
            'status' => 'posted',
            'amount' => 75,
            'currency' => 'KWD',
            'source' => 'cash_on_delivery',
            'created_by' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('custody_ledger_entries')->insert([
            'collection_account_id' => $accountId,
            'entry_type' => 'collection',
            'amount' => 75,
            'currency' => 'KWD',
            'reference_type' => 'collection_transaction',
            'reference_id' => $collectionId,
            'created_by' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('remittances')->insert([
            'collection_account_id' => $accountId,
            'idempotency_key' => 'VFS-REMIT-1',
            'amount' => 20,
            'currency' => 'KWD',
            'method' => 'bank_transfer',
            'reference' => 'REM-VFS-001',
            'status' => 'pending',
            'submitted_by' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($admin)
            ->get('/admin/van-finance-support')
            ->assertOk()
            ->assertSee('Van Finance Support')
            ->assertSee('VAN-SUPPORT-7')
            ->assertSee('KUWAIT-7007')
            ->assertSee('Van Finance Support Store')
            ->assertSee('75.000')
            ->assertSee('20.000')
            ->assertSee('55.000')
            ->assertDontSee('van #'.$vanId);

        $this->actingAs($admin)
            ->get('/admin/van-finance-support?ops_tab=collections')
            ->assertOk()
            ->assertSee('Collections &amp; Receipts', false)
            ->assertSee('Cash On Delivery')
            ->assertSee('Ledger collection')
            ->assertDontSee('VFS-COLLECTION-1');

        $this->actingAs($admin)
            ->get('/admin/van-finance-support?ops_tab=remittances')
            ->assertOk()
            ->assertSee('Remittances')
            ->assertSee('REM-VFS-001')
            ->assertSee('Bank Transfer')
            ->assertDontSee('VFS-REMIT-1');
    }

    public function test_admin_hub_exposes_van_finance_support_without_adding_another_sidebar_entry(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get('/admin/administration')
            ->assertOk()
            ->assertSee('data-admin-card="van-finance-support"', false)
            ->assertSee('/admin/van-finance-support', false)
            ->assertSee('Van Finance Support');
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Van Finance Support Admin',
            'email' => 'van-finance-support@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
