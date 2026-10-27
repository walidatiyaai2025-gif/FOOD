<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\CustodyLedgerEntry;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Services\FieldOperationsFinanceService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FieldOperationsFinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_and_remittance_views_are_store_scoped_filterable_and_authoritative(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS-FIN-1',
            'name' => 'Operations Finance Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS-FIN-2',
            'name' => 'Other Finance Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 41,
            'store_id' => $storeId,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $otherAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 99,
            'store_id' => $otherStoreId,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        CustodyLedgerEntry::query()->create([
            'collection_account_id' => $account->id,
            'entry_type' => 'collection',
            'amount' => 100,
            'currency' => 'KWD',
            'reference_type' => 'test',
            'reference_id' => 901,
            'created_by' => $actor->id,
        ]);
        CustodyLedgerEntry::query()->create([
            'collection_account_id' => $otherAccount->id,
            'entry_type' => 'collection',
            'amount' => 999,
            'currency' => 'KWD',
            'reference_type' => 'test',
            'reference_id' => 902,
            'created_by' => $actor->id,
        ]);

        app(CollectionCustodyService::class)->submitRemittance(
            $account,
            $actor,
            'ops-fin-remit-1',
            40,
            'KWD',
            'cash_office',
            'RCPT-41',
        );

        $service = app(FieldOperationsFinanceService::class);
        $wallets = $service->viewModel([$storeId], [
            'ops_tab' => 'wallets',
            'ops_q' => 'Operations Finance',
            'ops_status' => 'active',
            'ops_per_page' => 10,
        ]);

        $this->assertSame(1, $wallets['pagination']['total']);
        $this->assertSame($account->id, $wallets['rows'][0]['id']);
        $this->assertSame(100.0, (float) $wallets['rows'][0]['custody_balance']);
        $this->assertSame(40.0, (float) $wallets['rows'][0]['pending_remittance']);
        $this->assertSame(60.0, (float) $wallets['rows'][0]['available_to_remit']);

        $remittances = $service->viewModel([$storeId], [
            'ops_tab' => 'reconciliation',
            'ops_status' => 'pending',
            'ops_per_page' => 10,
        ]);

        $this->assertSame(1, $remittances['pagination']['total']);
        $this->assertSame('pending', $remittances['rows'][0]['status']);
        $this->assertSame('RCPT-41', $remittances['rows'][0]['reference']);
        $this->assertSame(0, (int) $remittances['rows'][0]['has_exception']);
    }

    public function test_driver_unsettled_collection_summary_uses_authoritative_custody_and_store_scope(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS-DRIVER-FIN-1',
            'name' => 'Driver Finance Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS-DRIVER-FIN-2',
            'name' => 'Other Driver Finance Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::factory()->create();

        $kwd = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 501,
            'store_id' => $storeId,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $egp = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 502,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
        ]);
        $outside = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 503,
            'store_id' => $otherStoreId,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        foreach ([
            [$kwd, 'collection', 120.0, 'KWD', 9101],
            [$kwd, 'approved_remittance', -20.0, 'KWD', 9102],
            [$egp, 'collection', 50.0, 'EGP', 9103],
            [$outside, 'collection', 999.0, 'KWD', 9104],
        ] as [$account, $type, $amount, $currency, $reference]) {
            CustodyLedgerEntry::query()->create([
                'collection_account_id' => $account->id,
                'entry_type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'reference_type' => 'test',
                'reference_id' => $reference,
                'created_by' => $actor->id,
            ]);
        }

        $finance = app(FieldOperationsFinanceService::class)->viewModel([$storeId], [
            'ops_tab' => 'wallets',
            'ops_per_page' => 10,
        ]);

        $this->assertSame([
            ['currency' => 'EGP', 'amount' => 50.0],
            ['currency' => 'KWD', 'amount' => 100.0],
        ], $finance['driver_unsettled_by_currency']);
    }
}
