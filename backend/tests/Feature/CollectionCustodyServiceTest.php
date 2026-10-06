<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\CustodyLedgerEntry;
use App\Models\User;
use App\Services\CollectionCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionCustodyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_remittance_reserves_available_amount_without_reducing_custody(): void
    {
        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'van_rep',
            'actor_id' => $actor->getKey(),
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        CustodyLedgerEntry::query()->create([
            'collection_account_id' => $account->getKey(),
            'entry_type' => 'collection',
            'amount' => 100,
            'currency' => 'KWD',
            'reference_type' => 'test',
            'reference_id' => 1,
            'created_by' => $actor->getKey(),
        ]);

        $service = app(CollectionCustodyService::class);
        $remittance = $service->submitRemittance(
            $account,
            $actor,
            'remit-1',
            40,
            'KWD',
            'cash_office',
        );

        $this->assertSame('pending', $remittance->status);
        $this->assertSame(100.0, $service->custodyBalance($account));
        $this->assertSame(60.0, $service->availableToRemit($account));
    }

    public function test_remittance_submission_is_idempotent(): void
    {
        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $actor->getKey(),
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        CustodyLedgerEntry::query()->create([
            'collection_account_id' => $account->getKey(),
            'entry_type' => 'collection',
            'amount' => 75,
            'currency' => 'KWD',
            'reference_type' => 'test',
            'reference_id' => 2,
            'created_by' => $actor->getKey(),
        ]);

        $service = app(CollectionCustodyService::class);
        $first = $service->submitRemittance($account, $actor, 'same-key', 25, 'KWD', 'cash_office');
        $second = $service->submitRemittance($account, $actor, 'same-key', 25, 'KWD', 'cash_office');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_approved_remittance_reduces_custody_once(): void
    {
        $actor = User::factory()->create();
        $approver = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $actor->getKey(),
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        CustodyLedgerEntry::query()->create([
            'collection_account_id' => $account->getKey(),
            'entry_type' => 'collection',
            'amount' => 100,
            'currency' => 'KWD',
            'reference_type' => 'test',
            'reference_id' => 3,
            'created_by' => $actor->getKey(),
        ]);

        $service = app(CollectionCustodyService::class);
        $remittance = $service->submitRemittance($account, $actor, 'approve-1', 30, 'KWD', 'cash_office');

        $service->approveRemittance($remittance, $approver);
        $service->approveRemittance($remittance, $approver);

        $this->assertSame(70.0, $service->custodyBalance($account));
        $this->assertDatabaseCount('custody_ledger_entries', 2);
    }
}
