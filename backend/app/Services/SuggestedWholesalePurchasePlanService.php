<?php

namespace App\Services;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SuggestedWholesalePurchasePlanService
{
    public function __construct(
        private readonly RetailReorderIntelligenceService $reorder,
        private readonly B2bAccountLedgerService $ledger,
        private readonly B2bPriceResolver $pricing,
    ) {}

    /**
     * @param list<array<string,mixed>> $recommendations
     * @return array<string,mixed>
     */
    public function previewFromRecommendations(
        array $recommendations,
        float $purchasingPower,
        ?float $requestedBudget = null,
    ): array {
        $creditLimit = max(0.0, $purchasingPower);
        $budget = $requestedBudget === null
            ? $creditLimit
            : min($creditLimit, max(0.0, $requestedBudget));
        $remaining = $budget;
        $rows = [];

        foreach ($recommendations as $row) {
            if (! (bool) data_get($row, 'recommendation.is_executable', false)) {
                continue;
            }

            $mapping = (array) ($row['mapping'] ?? []);
            $commercial = (array) ($row['commercial'] ?? []);
            $recommendation = (array) ($row['recommendation'] ?? []);
            if (
                ! isset($mapping['source_wholesale_product_id'], $mapping['source_wholesale_store_id'])
                || ! isset($commercial['unit_price'], $commercial['minimum_order_quantity'], $commercial['ordering_increment'])
            ) {
                continue;
            }

            $unitPrice = max(0.0, (float) $commercial['unit_price']);
            $availabilityExecutable = max(
                0.0,
                (float) ($recommendation['recommended_wholesale_quantity'] ?? 0),
            );
            $budgetExecutable = $this->affordableQuantity(
                $availabilityExecutable,
                $remaining,
                $unitPrice,
                (float) $commercial['minimum_order_quantity'],
                (float) $commercial['ordering_increment'],
            );
            $allocatedCost = round($budgetExecutable * $unitPrice, 3);
            $remaining = max(0.0, round($remaining - $allocatedCost, 3));

            $rows[] = [
                'retail_product_id' => (int) $row['retail_product_id'],
                'name' => (string) ($row['name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'priority_score' => (float) ($row['priority_score'] ?? 0),
                'wholesale_store_id' => (int) $mapping['source_wholesale_store_id'],
                'wholesale_product_id' => (int) $mapping['source_wholesale_product_id'],
                'wholesale_name' => (string) ($mapping['source_name'] ?? ''),
                'wholesale_sku' => (string) ($mapping['source_sku'] ?? ''),
                'unit_price' => round($unitPrice, 3),
                'minimum_quantity' => round((float) $commercial['minimum_order_quantity'], 3),
                'ordering_increment' => round((float) $commercial['ordering_increment'], 3),
                'pack_size' => round((float) ($commercial['pack_size'] ?? 1), 3),
                'case_size' => $commercial['case_size'] === null
                    ? null
                    : round((float) $commercial['case_size'], 3),
                'available_quantity' => round((float) ($commercial['wholesale_available_quantity'] ?? 0), 3),
                'full_need_quantity' => round(
                    (float) ($recommendation['unconstrained_wholesale_quantity'] ?? 0),
                    3,
                ),
                'availability_executable_quantity' => round($availabilityExecutable, 3),
                'budget_executable_quantity' => round($budgetExecutable, 3),
                'full_need_cost' => round(
                    max(0.0, (float) ($recommendation['unconstrained_wholesale_quantity'] ?? 0)) * $unitPrice,
                    3,
                ),
                'availability_executable_cost' => round($availabilityExecutable * $unitPrice, 3),
                'budget_executable_cost' => $allocatedCost,
                'availability_limited' => (string) ($recommendation['state'] ?? '') === 'availability_limited',
                'credit_limited' => $budgetExecutable + 0.0001 < $availabilityExecutable,
                'explanation' => (array) ($row['explanation'] ?? []),
            ];
        }

        return [
            'currency' => 'KWD',
            'purchasing_power' => round($creditLimit, 3),
            'requested_budget' => $requestedBudget === null ? null : round(max(0.0, $requestedBudget), 3),
            'effective_budget' => round($budget, 3),
            'allocated_cost' => round($budget - $remaining, 3),
            'remaining_budget' => round($remaining, 3),
            'rows' => $rows,
        ];
    }

    /**
     * Recompute W4 and revalidate price, quantity rules and availability before
     * writing to the existing B2B cart. Quantities are cart target quantities,
     * so retrying the same reviewed plan is idempotent.
     *
     * @param array<int|string,mixed> $requestedQuantities
     * @return array<string,mixed>
     */
    public function apply(
        User $user,
        int $retailStoreId,
        array $requestedQuantities,
        ?float $requestedBudget = null,
    ): array {
        $customer = $this->ownerCustomer($user, $retailStoreId);
        $finance = $this->ledger->summary($customer);
        $purchasingPower = max(0.0, (float) $finance['purchasing_power']);
        $effectiveBudget = $requestedBudget === null
            ? $purchasingPower
            : min($purchasingPower, max(0.0, $requestedBudget));

        $fresh = $this->reorder->forStore($retailStoreId);
        $rows = collect($fresh['recommendations'])
            ->filter(fn (array $row): bool => (bool) data_get($row, 'recommendation.is_executable', false))
            ->keyBy(fn (array $row): int => (int) $row['retail_product_id']);

        $selected = [];
        $selectedCost = 0.0;

        foreach ($requestedQuantities as $retailProductId => $rawQuantity) {
            $quantity = is_numeric($rawQuantity) ? (float) $rawQuantity : 0.0;
            if ($quantity <= 0) {
                continue;
            }

            $row = $rows->get((int) $retailProductId);
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'quantities' => ['A selected recommendation is no longer executable. Refresh and review the current plan.'],
                ]);
            }

            $mapping = (array) ($row['mapping'] ?? []);
            $commercial = (array) ($row['commercial'] ?? []);
            $recommendation = (array) ($row['recommendation'] ?? []);
            $maxQuantity = max(0.0, (float) ($recommendation['recommended_wholesale_quantity'] ?? 0));
            $this->assertValidQuantity(
                $quantity,
                (float) ($commercial['minimum_order_quantity'] ?? 0),
                (float) ($commercial['ordering_increment'] ?? 0),
            );
            if ($quantity > $maxQuantity + 0.0001) {
                throw ValidationException::withMessages([
                    'quantities' => ['A selected quantity exceeds the latest executable recommendation or live availability.'],
                ]);
            }

            $storeId = (int) ($mapping['source_wholesale_store_id'] ?? 0);
            $productId = (int) ($mapping['source_wholesale_product_id'] ?? 0);
            if ($storeId <= 0 || $productId <= 0) {
                throw ValidationException::withMessages([
                    'quantities' => ['A selected recommendation no longer has one authoritative Wholesale source.'],
                ]);
            }

            $pricing = $this->pricing->resolve($customer, $storeId, $productId);
            $this->assertValidQuantity(
                $quantity,
                (float) $pricing['minimum_quantity'],
                (float) $pricing['ordering_increment'],
            );
            $available = $this->liveAvailable($storeId, $productId);
            if ($quantity > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'quantities' => ['A selected quantity exceeds current Wholesale availability. Refresh and review the plan.'],
                ]);
            }

            $lineCost = round($quantity * (float) $pricing['price'], 3);
            $selectedCost = round($selectedCost + $lineCost, 3);
            $selected[] = [
                'store_id' => $storeId,
                'product_id' => $productId,
                'quantity' => round($quantity, 3),
            ];
        }

        if ($selected === []) {
            throw ValidationException::withMessages([
                'quantities' => ['Select at least one executable recommendation before adding the plan to the Wholesale cart.'],
            ]);
        }

        if ($selectedCost > $effectiveBudget + 0.0001) {
            throw ValidationException::withMessages([
                'budget' => ['The reviewed plan exceeds the latest available purchasing power or selected budget.'],
            ]);
        }

        $result = DB::transaction(function () use ($customer, $selected): array {
            $cartIds = [];
            $changedLines = 0;

            foreach ($selected as $line) {
                $this->assertActiveWholesaleProduct($line['store_id'], $line['product_id']);
                $pricing = $this->pricing->resolve($customer, $line['store_id'], $line['product_id']);
                $this->assertValidQuantity(
                    $line['quantity'],
                    (float) $pricing['minimum_quantity'],
                    (float) $pricing['ordering_increment'],
                );
                $available = $this->liveAvailable($line['store_id'], $line['product_id']);
                if ($line['quantity'] > $available + 0.0001) {
                    throw ValidationException::withMessages([
                        'quantities' => ['Wholesale availability changed while the plan was being applied. Refresh and review again.'],
                    ]);
                }

                $cart = Cart::query()->firstOrCreate(
                    [
                        'store_id' => $line['store_id'],
                        'b2b_customer_id' => $customer->getKey(),
                        'channel' => 'b2b',
                    ],
                    [
                        'customer_id' => $customer->legacy_customer_id,
                        'guest_token' => null,
                    ],
                );

                $item = CartItem::query()
                    ->where('cart_id', $cart->getKey())
                    ->where('product_id', $line['product_id'])
                    ->lockForUpdate()
                    ->first();

                $existingQuantity = $item instanceof CartItem ? (float) $item->quantity : 0.0;
                $targetQuantity = max($existingQuantity, (float) $line['quantity']);
                $this->assertValidQuantity(
                    $targetQuantity,
                    (float) $pricing['minimum_quantity'],
                    (float) $pricing['ordering_increment'],
                );
                if ($targetQuantity > $available + 0.0001) {
                    throw ValidationException::withMessages([
                        'quantities' => ['The existing Wholesale cart quantity exceeds current availability. Review the cart before applying the plan.'],
                    ]);
                }

                if (! $item instanceof CartItem) {
                    $item = new CartItem([
                        'cart_id' => $cart->getKey(),
                        'product_id' => $line['product_id'],
                    ]);
                }

                if (! $item->exists || abs((float) $item->quantity - $targetQuantity) > 0.0001) {
                    $changedLines++;
                }
                $item->quantity = round($targetQuantity, 3);
                $item->unit_price_snapshot = (float) $pricing['price'];
                $item->save();
                $cartIds[(int) $cart->getKey()] = true;
            }

            return [
                'cart_ids' => array_map('intval', array_keys($cartIds)),
                'changed_lines' => $changedLines,
            ];
        });

        return [
            ...$result,
            'selected_lines' => count($selected),
            'selected_cost' => $selectedCost,
            'effective_budget' => round($effectiveBudget, 3),
            'purchasing_power' => round($purchasingPower, 3),
            'currency' => (string) $finance['currency'],
        ];
    }

    private function ownerCustomer(User $user, int $retailStoreId): B2bCustomer
    {
        $customerId = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $retailStoreId)
            ->where('owner_user_id', $user->getKey())
            ->value('b2b_customer_id');

        abort_if($customerId === null, 403, 'Only the Retail store owner can mutate the linked personal Wholesale cart.');

        return B2bCustomer::query()->findOrFail((int) $customerId);
    }

    private function liveAvailable(int $storeId, int $productId): float
    {
        $row = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->selectRaw('COALESCE(SUM(inventories.quantity - inventories.reserved_quantity), 0) as available')
            ->first();

        return round(max(0.0, (float) ($row->available ?? 0)), 3);
    }

    private function assertActiveWholesaleProduct(int $storeId, int $productId): void
    {
        $exists = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $storeId);
            })
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->exists();

        abort_unless($exists, 409, 'The selected Wholesale product is no longer available in its source store.');
    }

    private function assertValidQuantity(float $quantity, float $minimum, float $increment): void
    {
        $minimum = max(0.001, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity + 0.0001 < $minimum) {
            throw ValidationException::withMessages([
                'quantities' => ['A selected quantity is below the current Wholesale minimum.'],
            ]);
        }

        $steps = ($quantity - $minimum) / $increment;
        if (abs($steps - round($steps)) >= 0.0001) {
            throw ValidationException::withMessages([
                'quantities' => ['A selected quantity does not match the current Wholesale ordering increment.'],
            ]);
        }
    }

    private function affordableQuantity(
        float $desired,
        float $remainingBudget,
        float $unitPrice,
        float $minimum,
        float $increment,
    ): float {
        if ($desired <= 0 || $unitPrice <= 0 || $remainingBudget <= 0) {
            return 0.0;
        }

        $minimum = max(0.001, $minimum);
        $increment = max(0.001, $increment);
        $maximumByBudget = $remainingBudget / $unitPrice;
        $capped = min($desired, $maximumByBudget);
        if ($capped + 0.0001 < $minimum) {
            return 0.0;
        }

        $steps = (int) floor((($capped - $minimum) / $increment) + 0.0000001);

        return round(min($desired, $minimum + (max(0, $steps) * $increment)), 3);
    }
}
