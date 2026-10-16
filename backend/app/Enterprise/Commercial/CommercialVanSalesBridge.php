<?php

namespace App\Enterprise\Commercial;

use App\Models\Order;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\B2BOrderRoutingCoordinator;
use App\Services\CustomerDomainResolver;
use App\Services\OrderInventoryReservationService;
use Illuminate\Http\Request;

final class CommercialVanSalesBridge
{
    public const CONTRACT_VERSION = 1;

    public function __construct(
        private readonly AdminOrderManagementService $orders,
    ) {}

    /** @return array<string, mixed> */
    public function quote(
        Request $request,
        int $customerId,
        int $storeId,
    ): array {
        $request->merge(['customer_id' => $customerId]);

        $quote = $this->orders->quote(
            $request,
            'b2b',
            $storeId,
            'van',
        );

        return [
            ...$quote,
            'van_commercial_contract' => $this->contract($storeId, $customerId),
        ];
    }

    public function create(
        Request $request,
        User $actor,
        int $customerId,
        int $storeId,
        string $idempotencyKey,
    ): Order {
        $request->merge(['customer_id' => $customerId]);

        return $this->orders->create(
            $request,
            $actor,
            'b2b',
            $storeId,
            'van',
            'van',
            $idempotencyKey,
        );
    }

    /** @return array<string, mixed> */
    private function contract(int $storeId, int $customerId): array
    {
        return [
            'version' => self::CONTRACT_VERSION,
            'origin' => 'van',
            'channel' => 'b2b',
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'customer_authority' => CustomerDomainResolver::class,
            'pricing_authority' => CommercialPricingResolver::class,
            'credit_authority' => CommercialCreditEligibilityPolicy::class,
            'order_authority' => Order::class,
            'inventory_authority' => OrderInventoryReservationService::class,
            'routing_authority' => B2BOrderRoutingCoordinator::class,
            'command_identity' => 'Idempotency-Key',
            'stock_source' => 'inventories',
        ];
    }
}
