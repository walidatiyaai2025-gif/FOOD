<?php

namespace App\Services;

use App\Models\Order;

/**
 * Previous account-credit invoices must be settled before a later order
 * is handed over. This never blocks placing the later order and never
 * conflates the later order's own credit balance with delivery state.
 */
final class PriorCreditDeliveryGuard
{
    public function __construct(private readonly DeliverySettlementService $settlements) {}

    public function assertNoEarlierDebt(Order $current): void
    {
        $channel = strtolower((string) $current->channel);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            return;
        }

        $customerColumn = $channel === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $customerId = $current->getAttribute($customerColumn);
        if ($customerId === null) {
            return;
        }

        // Chunk to keep the gate bounded even for long-lived customer accounts.
        $earlierOrders = Order::query()
            ->where('store_id', $current->store_id)
            ->where('channel', $channel)
            ->where($customerColumn, $customerId)
            ->where('id', '<', $current->getKey())
            ->whereIn('payment_method', ['account_credit', 'account_debt'])
            ->whereNotIn('status', ['cancelled', 'voided'])
            ->orderBy('id');

        $earlierOrders->chunkById(100, function ($orders): void {
            $summaries = $this->settlements->summarizeMany($orders->all());
            foreach ($orders as $older) {
                $remaining = (float) ($summaries[(int) $older->getKey()]['remaining_amount'] ?? 0);
                if ($remaining <= 0.0001) {
                    continue;
                }

                abort(409, app()->getLocale() === 'ar'
                    ? 'لا يمكن تسليم الطلب الحالي قبل تحصيل وتسوية مديونية الطلب السابق رقم '.$older->getKey().'. المتبقي: '.number_format($remaining, 2).' جنيه.'
                    : 'Settle the earlier credit order #'.$older->getKey().' before delivering this order. Outstanding: EGP '.number_format($remaining, 2).'.');
            }
        });
    }
}
