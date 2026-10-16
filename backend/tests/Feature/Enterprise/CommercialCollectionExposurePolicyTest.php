<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialCollectionExposurePolicy;
use App\Enterprise\Commercial\CommercialTruthRegistry;
use App\Models\CollectionAccount;
use App\Models\CustodyLedgerEntry;
use App\Models\Remittance;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\CollectionCustodyService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CommercialCollectionExposurePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_collection_exposure_reuses_authoritative_custody_and_pending_remittance_semantics(): void
    {
        [$actor, $store, $account] = $this->fixture('COMM-COLLECTION');
        $custody = app(CollectionCustodyService::class);
        $custody->collect(
            $account,
            $actor,
            'commercial-collection-001',
            100,
            'KWD',
            'commercial_convergence_test',
        );
        $pending = $custody->submitRemittance(
            $account,
            $actor,
            'commercial-remittance-001',
            40,
            'KWD',
            'cash_office',
        );

        $policy = app(CommercialCollectionExposurePolicy::class);
        $summary = $policy->summary($actor, $store->id, $account->id);

        $this->assertSame($account->id, $summary['account_id']);
        $this->assertSame($store->id, $summary['store_id']);
        $this->assertSame('KWD', $summary['currency']);
        $this->assertSame(100.0, $summary['custody_balance']);
        $this->assertSame(40.0, $summary['pending_remittance']);
        $this->assertSame(60.0, $summary['available_to_remit']);
        $this->assertSame($pending->id, $summary['recent_remittances']->first()?->id);
        $this->assertDatabaseCount('custody_ledger_entries', 1);

        $eligible = $policy->evaluateRemittance($actor, $store->id, $account->id, 50);
        $this->assertTrue($eligible['eligible']);
        $this->assertSame(CommercialCollectionExposurePolicy::REASON_ELIGIBLE, $eligible['reason']);
        $this->assertSame(0.0, $eligible['shortfall']);

        $blocked = $policy->evaluateRemittance($actor, $store->id, $account->id, 65);
        $this->assertFalse($blocked['eligible']);
        $this->assertSame(
            CommercialCollectionExposurePolicy::REASON_INSUFFICIENT_CUSTODY,
            $blocked['reason'],
        );
        $this->assertSame(5.0, $blocked['shortfall']);

        $this->assertDatabaseCount('collection_accounts', 1);
        $this->assertDatabaseCount('collection_transactions', 1);
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_recent_remittance_history_is_bounded_to_one_hundred_rows(): void
    {
        [$actor, $store, $account] = $this->fixture('COMM-BOUNDED');

        $rows = [];
        $now = now();
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'collection_account_id' => $account->id,
                'idempotency_key' => 'commercial-bound-'.$i,
                'amount' => '1.000',
                'currency' => 'KWD',
                'method' => 'cash_office',
                'reference' => 'REF-'.$i,
                'status' => 'rejected',
                'submitted_by' => $actor->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $now,
                'created_at' => $now->copy()->subSeconds(105 - $i),
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('remittances')->insert($chunk);
        }

        $summary = app(CommercialCollectionExposurePolicy::class)
            ->summary($actor, $store->id, $account->id, 500);

        $this->assertCount(100, $summary['recent_remittances']);
        $this->assertSame('REF-105', $summary['recent_remittances']->first()?->reference);
    }

    public function test_collection_exposure_denies_foreign_store_and_unscoped_account_access(): void
    {
        [$actor, $store, $account] = $this->fixture('COMM-SCOPE');
        $otherStore = $this->otherWholesaleStore($store);
        $foreignAccount = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 991,
            'store_id' => $otherStore->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        $policy = app(CommercialCollectionExposurePolicy::class);

        try {
            $policy->summary($actor, $store->id, $foreignAccount->id);
            $this->fail('Foreign-store collection account must be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $policy->summary($actor, $otherStore->id, $foreignAccount->id);
            $this->fail('Actor must not read a store outside assigned scope.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $unscoped = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 992,
            'store_id' => null,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        $this->expectException(ModelNotFoundException::class);
        $policy->summary($actor, $store->id, $unscoped->id);
    }

    public function test_truth_registry_keeps_collection_write_authority_and_adds_read_decision_support_only(): void
    {
        $collection = app(CommercialTruthRegistry::class)->authority('collection', 'b2b');

        $this->assertSame(CollectionCustodyService::class, $collection['read_authority']);
        $this->assertSame(CollectionCustodyService::class, $collection['write_authority']);
        $this->assertContains(
            CommercialCollectionExposurePolicy::class,
            $collection['supporting_authorities'],
        );
        $this->assertContains('custody_ledger_entries', $collection['storage']);
        $this->assertContains('remittances', $collection['storage']);
    }

    public function test_invalid_remittance_amount_fails_before_balance_decision(): void
    {
        [$actor, $store, $account] = $this->fixture('COMM-AMOUNT');

        $this->expectException(ValidationException::class);

        app(CommercialCollectionExposurePolicy::class)
            ->evaluateRemittance($actor, $store->id, $account->id, 0);
    }

    /** @return array{User,Store,CollectionAccount} */
    private function fixture(string $code): array
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $actor = User::factory()->create([
            'email' => strtolower($code).'@example.test',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);
        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => $actor->id,
            'store_id' => $store->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);

        return [$actor, $store, $account];
    }

    private function otherWholesaleStore(Store $source): Store
    {
        return Store::query()->create([
            'store_type_id' => $source->store_type_id,
            'code' => uniqid('COMM-OTHER-', false),
            'name' => 'Other Wholesale Store',
            'is_active' => true,
        ]);
    }
}
