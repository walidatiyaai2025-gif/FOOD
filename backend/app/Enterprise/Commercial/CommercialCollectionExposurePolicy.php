<?php

namespace App\Enterprise\Commercial;

use App\Models\CollectionAccount;
use App\Models\Remittance;
use App\Models\User;
use App\Services\CollectionCustodyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CommercialCollectionExposurePolicy
{
    public const REASON_ELIGIBLE = 'ELIGIBLE';

    public const REASON_INSUFFICIENT_CUSTODY = 'INSUFFICIENT_CUSTODY';

    public function __construct(
        private readonly CommercialContextGuard $guard,
        private readonly CollectionCustodyService $custody,
    ) {}

    /**
     * @return array{
     *     account_id:int,
     *     store_id:int,
     *     currency:string,
     *     custody_balance:float,
     *     pending_remittance:float,
     *     available_to_remit:float,
     *     recent_remittances:Collection<int, Remittance>
     * }
     */
    public function summary(
        User $actor,
        int $storeId,
        int $accountId,
        int $recentLimit = 10,
    ): array {
        $account = $this->account($actor, $storeId, $accountId);
        $custody = round($this->custody->custodyBalance($account), 3);
        $available = round($this->custody->availableToRemit($account), 3);
        $limit = max(1, min(100, $recentLimit));

        return [
            'account_id' => (int) $account->getKey(),
            'store_id' => $storeId,
            'currency' => strtoupper((string) $account->currency),
            'custody_balance' => $custody,
            'pending_remittance' => round(max($custody - $available, 0), 3),
            'available_to_remit' => $available,
            'recent_remittances' => Remittance::query()
                ->where('collection_account_id', $account->getKey())
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * @return array{
     *     eligible:bool,
     *     reason:string,
     *     currency:string,
     *     proposed_amount:float,
     *     available_to_remit:float,
     *     shortfall:float
     * }
     */
    public function evaluateRemittance(
        User $actor,
        int $storeId,
        int $accountId,
        float $proposedAmount,
    ): array {
        if ($proposedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Proposed remittance amount must be greater than zero.'],
            ]);
        }

        $summary = $this->summary($actor, $storeId, $accountId, 1);
        $proposed = round($proposedAmount, 3);
        $available = $summary['available_to_remit'];
        $shortfall = round(max($proposed - $available, 0), 3);

        return [
            'eligible' => $shortfall === 0.0,
            'reason' => $shortfall === 0.0
                ? self::REASON_ELIGIBLE
                : self::REASON_INSUFFICIENT_CUSTODY,
            'currency' => $summary['currency'],
            'proposed_amount' => $proposed,
            'available_to_remit' => $available,
            'shortfall' => $shortfall,
        ];
    }

    private function account(User $actor, int $storeId, int $accountId): CollectionAccount
    {
        if ($storeId < 1 || $accountId < 1) {
            throw ValidationException::withMessages([
                'scope' => ['A valid store and collection account are required.'],
            ]);
        }

        $this->guard->assert($actor, $storeId, 'b2b', 'finance.view');

        $account = CollectionAccount::query()
            ->whereKey($accountId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->first();

        if (! $account instanceof CollectionAccount) {
            throw (new ModelNotFoundException)->setModel(CollectionAccount::class, [$accountId]);
        }

        return $account;
    }
}
