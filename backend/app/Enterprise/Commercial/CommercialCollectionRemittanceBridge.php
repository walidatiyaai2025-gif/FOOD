<?php

namespace App\Enterprise\Commercial;

use App\Models\CollectionAccount;
use App\Models\Invoice;
use App\Models\Remittance;
use App\Models\User;
use App\Services\CollectionCustodyService;
use Illuminate\Validation\ValidationException;

final class CommercialCollectionRemittanceBridge
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly CommercialContextGuard $guard,
        private readonly CollectionCustodyService $custody,
        private readonly CommercialEventFactory $events,
    ) {}

    /**
     * @return array{
     *     contract_version:int,
     *     idempotency_key:string,
     *     remittance:array{id:int,status:string,amount:float,currency:string,reference:string},
     *     commercial_reference:array{store_id:int,order_id:int,invoice_id:int,collection_account_id:int},
     *     treasury_handoff:array{kind:string,optional:bool,provider_neutral:bool},
     *     event:CommercialEventEnvelope
     * }
     */
    public function submit(
        User $actor,
        int $storeId,
        int $collectionAccountId,
        int $orderId,
        int $invoiceId,
        float $amount,
        string $currency,
        string $businessReference,
        string $method = 'treasury_handoff',
    ): array {
        $currency = strtoupper(trim($currency));
        $businessReference = trim($businessReference);
        $method = trim($method);

        if ($businessReference === '' || mb_strlen($businessReference) > 255) {
            throw ValidationException::withMessages([
                'reference' => ['A commercial remittance reference of at most 255 characters is required.'],
            ]);
        }

        if ($method === '' || mb_strlen($method) > 64) {
            throw ValidationException::withMessages([
                'method' => ['A remittance method of at most 64 characters is required.'],
            ]);
        }

        $this->guard->assert($actor, $storeId, 'b2b', 'finance.view');

        $account = CollectionAccount::query()
            ->whereKey($collectionAccountId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->firstOrFail();

        $this->guard->assert($actor, $storeId, 'b2b', 'orders.view');

        $order = Order::query()
            ->whereKey($orderId)
            ->where('store_id', $storeId)
            ->whereRaw('LOWER(channel) = ?', ['b2b'])
            ->firstOrFail();

        $invoice = Invoice::query()
            ->whereKey($invoiceId)
            ->where('order_id', $orderId)
            ->firstOrFail();

        if (strtoupper((string) $invoice->currency) !== $currency) {
            throw ValidationException::withMessages([
                'currency' => ['Invoice and remittance currency must match.'],
            ]);
        }

        $idempotencyKey = hash('sha256', implode(':', [
            'foodex-commercial-remittance',
            (string) self::CONTRACT_VERSION,
            (string) $storeId,
            (string) $collectionAccountId,
            (string) $orderId,
            (string) $invoiceId,
            $businessReference,
        ]));

        $remittance = $this->custody->submitRemittance(
            $account,
            $actor,
            $idempotencyKey,
            $amount,
            $currency,
            $method,
            $businessReference,
            'Commercial convergence handoff; source remains CollectionCustodyService.',
        );

        $this->assertRetryContract(
            $remittance,
            $collectionAccountId,
            $amount,
            $currency,
            $businessReference,
        );

        $commercialReference = [
            'store_id' => $storeId,
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'collection_account_id' => $collectionAccountId,
        ];

        $treasuryHandoff = [
            'kind' => 'treasury.receipt.intent',
            'optional' => true,
            'provider_neutral' => true,
        ];

        $event = $this->events->make(
            'collection',
            'b2b',
            $storeId,
            'remittance',
            (int) $remittance->getKey(),
            [
                'idempotency_key' => $idempotencyKey,
                'commercial_reference' => $commercialReference,
                'treasury_handoff' => $treasuryHandoff,
                'remittance_status' => (string) $remittance->status,
            ],
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'idempotency_key' => $idempotencyKey,
            'remittance' => [
                'id' => (int) $remittance->getKey(),
                'status' => (string) $remittance->status,
                'amount' => (float) $remittance->amount,
                'currency' => (string) $remittance->currency,
                'reference' => (string) $remittance->reference,
            ],
            'commercial_reference' => $commercialReference,
            'treasury_handoff' => $treasuryHandoff,
            'event' => $event,
        ];
    }

    /**
     * @return array{
     *     id:int,
     *     status:string,
     *     rejected:bool,
     *     reconciled:bool,
     *     reference:string,
     *     reviewed_at:string|null
     * }
     */
    public function status(
        User $actor,
        int $storeId,
        int $collectionAccountId,
        int $remittanceId,
    ): array {
        $this->guard->assert($actor, $storeId, 'b2b', 'finance.view');

        CollectionAccount::query()
            ->whereKey($collectionAccountId)
            ->where('store_id', $storeId)
            ->where('status', 'active')
            ->firstOrFail();

        $remittance = Remittance::query()
            ->whereKey($remittanceId)
            ->where('collection_account_id', $collectionAccountId)
            ->firstOrFail();

        $status = (string) $remittance->status;

        return [
            'id' => (int) $remittance->getKey(),
            'status' => $status,
            'rejected' => $status === 'rejected',
            'reconciled' => $status === 'reconciled',
            'reference' => (string) $remittance->reference,
            'reviewed_at' => $remittance->reviewed_at?->toISOString(),
        ];
    }

    private function assertRetryContract(
        Remittance $remittance,
        int $collectionAccountId,
        float $amount,
        string $currency,
        string $businessReference,
    ): void {
        $sameAmount = abs((float) $remittance->amount - $amount) < 0.0005;

        if (
            (int) $remittance->collection_account_id !== $collectionAccountId
            || ! $sameAmount
            || strtoupper((string) $remittance->currency) !== $currency
            || (string) $remittance->reference !== $businessReference
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The commercial remittance reference was already used with different request data.'],
            ]);
        }
    }
}
