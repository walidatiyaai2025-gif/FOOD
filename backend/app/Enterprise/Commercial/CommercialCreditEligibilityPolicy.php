<?php

namespace App\Enterprise\Commercial;

use App\Models\B2bCustomer;
use App\Services\B2bAccountLedgerService;
use Illuminate\Validation\ValidationException;

final class CommercialCreditEligibilityPolicy
{
    public const REASON_ELIGIBLE = 'ELIGIBLE';

    public const REASON_PURCHASING_POWER_EXCEEDED = 'PURCHASING_POWER_EXCEEDED';

    public function __construct(
        private readonly B2bAccountLedgerService $ledger,
    ) {}

    /**
     * @return array{
     *     eligible:bool,
     *     reason:string,
     *     currency:string,
     *     proposed_amount:float,
     *     credit_limit:float,
     *     outstanding_receivable:float,
     *     customer_credit_balance:float,
     *     purchasing_power:float,
     *     shortfall:float
     * }
     */
    public function evaluate(B2bCustomer $customer, int $storeId, float $proposedAmount): array
    {
        if ($storeId <= 0) {
            throw ValidationException::withMessages([
                'store_id' => ['A valid store is required for credit eligibility.'],
            ]);
        }

        $proposedAmount = round($proposedAmount, 3);
        if ($proposedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Proposed account-credit amount must be greater than zero.'],
            ]);
        }

        $summary = $this->ledger->summary($customer, $storeId);
        $purchasingPower = round((float) $summary['purchasing_power'], 3);
        $shortfall = round(max($proposedAmount - $purchasingPower, 0), 3);
        $eligible = $shortfall === 0.0;

        return [
            'eligible' => $eligible,
            'reason' => $eligible
                ? self::REASON_ELIGIBLE
                : self::REASON_PURCHASING_POWER_EXCEEDED,
            'currency' => (string) $summary['currency'],
            'proposed_amount' => $proposedAmount,
            'credit_limit' => round((float) $summary['credit_limit'], 3),
            'outstanding_receivable' => round((float) $summary['outstanding_receivable'], 3),
            'customer_credit_balance' => round((float) $summary['customer_credit_balance'], 3),
            'purchasing_power' => $purchasingPower,
            'shortfall' => $shortfall,
        ];
    }

    /**
     * @return array{
     *     eligible:bool,
     *     reason:string,
     *     currency:string,
     *     proposed_amount:float,
     *     credit_limit:float,
     *     outstanding_receivable:float,
     *     customer_credit_balance:float,
     *     purchasing_power:float,
     *     shortfall:float
     * }
     */
    public function assertEligible(B2bCustomer $customer, int $storeId, float $proposedAmount): array
    {
        $decision = $this->evaluate($customer, $storeId, $proposedAmount);

        abort_if(
            ! $decision['eligible'],
            409,
            'The order exceeds the available B2B purchasing power.',
        );

        return $decision;
    }
}
