<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P272: distinguish real B2B Van custody from an unproven "trip settlement".
 *
 * A Van account balance must not be represented as a closed trip's amount
 * without a canonical trip-to-collection and trip-to-remittance attribution.
 */
final class V2VanTripSettlementProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_van_finance_custody_read_is_b2b_only_and_never_writes_a_settlement(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        $now = now();
        $b2bStore = $this->store('B2B', 'P272-B2B');
        $b2cStore = $this->store('B2C', 'P272-B2C');
        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'P272-VAN',
            'plate_number' => 'P272-001',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$b2bStore => '43.500', $b2cStore => '999.000'] as $storeId => $amount) {
            $accountId = (int) DB::table('collection_accounts')->insertGetId([
                'actor_type' => 'van',
                'actor_id' => $vanId,
                'store_id' => (int) $storeId,
                'currency' => 'KWD',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('custody_ledger_entries')->insert([
                'collection_account_id' => $accountId,
                'entry_type' => 'collection',
                'amount' => $amount,
                'currency' => 'KWD',
                'reference_type' => 'p272-test-collection',
                'reference_id' => $accountId,
                'created_by' => $admin->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin->fresh())
                ->get('/admin/van-finance-support?ops_tab=wallets')
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $finance = $response->viewData('finance');
            $this->assertSame(1, $finance['pagination']['total']);
            $this->assertCount(1, $finance['rows']);
            $this->assertSame($b2bStore, (int) $finance['rows'][0]['store_id']);
            $this->assertEqualsWithDelta(43.5, (float) $finance['rows'][0]['custody_balance'], 0.001);
            $this->assertSame('KWD', $finance['rows'][0]['currency']);
            $this->assertDatabaseCount('custody_ledger_entries', 2);
            $this->assertDatabaseCount('remittances', 0);
        }
    }

    public function test_actor_without_finance_authority_cannot_access_van_finance_support(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)
            ->get('/admin/van-finance-support?ops_tab=reconciliation')
            ->assertForbidden();
    }

    public function test_existing_custody_schema_cannot_prove_an_individual_trip_closeout(): void
    {
        $this->assertTrue(Schema::hasColumn('van_visits', 'order_id'));
        $this->assertTrue(Schema::hasColumn('van_visits', 'store_id'));
        $this->assertFalse(Schema::hasColumn('van_visits', 'trip_id'));
        $this->assertFalse(Schema::hasColumn('collection_transactions', 'van_visit_id'));
        $this->assertFalse(Schema::hasColumn('remittances', 'trip_id'));
        $this->assertFalse(Schema::hasColumn('remittance_allocations', 'van_visit_id'));
        $this->assertFalse(Schema::hasColumn('custody_ledger_entries', 'trip_id'));
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
