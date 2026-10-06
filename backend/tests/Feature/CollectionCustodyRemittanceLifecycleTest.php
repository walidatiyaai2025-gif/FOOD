<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\User;
use App\Services\CollectionCustodyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CollectionCustodyRemittanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejecting_pending_remittance_preserves_custody_and_releases_reserved_amount(): void
    {
        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 201,
            'store_id' => null,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $service = app(CollectionCustodyService::class);
        $service->collect($account, $actor, 'collection-reject-seed', 20, 'KWD', 'test');
        $remittance = $service->submitRemittance($account, $actor, 'remit-reject-1', 8, 'KWD', 'cash');

        $this->assertSame(12.0, $service->availableToRemit($account));

        $rejected = $service->rejectRemittance($remittance, $actor);

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame(20.0, $service->custodyBalance($account));
        $this->assertSame(20.0, $service->availableToRemit($account));
    }

    public function test_pending_remittance_cannot_be_reconciled(): void
    {
        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 301,
            'store_id' => null,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $service = app(CollectionCustodyService::class);
        $service->collect($account, $actor, 'collection-pending-seed', 30, 'KWD', 'test');
        $pending = $service->submitRemittance($account, $actor, 'remit-pending-1', 12, 'KWD', 'cash');

        $this->expectException(ValidationException::class);
        $service->reconcileRemittance($pending, $actor);
    }

    public function test_reconcile_requires_approval_evidence_and_never_double_debits_custody(): void
    {
        $actor = User::factory()->create();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'van',
            'actor_id' => 302,
            'store_id' => null,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $service = app(CollectionCustodyService::class);
        $service->collect($account, $actor, 'collection-reconcile-seed', 30, 'KWD', 'test');
        $pending = $service->submitRemittance($account, $actor, 'remit-reconcile-1', 12, 'KWD', 'cash');

        $approved = $service->approveRemittance($pending, $actor);
        $balanceAfterApproval = $service->custodyBalance($account);
        $reconciled = $service->reconcileRemittance($approved, $actor);
        $reconciledAgain = $service->reconcileRemittance($reconciled, $actor);

        $this->assertSame('reconciled', $reconciled->status);
        $this->assertSame($reconciled->id, $reconciledAgain->id);
        $this->assertSame(18.0, $balanceAfterApproval);
        $this->assertSame($balanceAfterApproval, $service->custodyBalance($account));
        $this->assertDatabaseCount('custody_ledger_entries', 2);
    }
}
