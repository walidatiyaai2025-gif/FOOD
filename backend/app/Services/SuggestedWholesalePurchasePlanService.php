<?php

namespace App\Services;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SuggestedWholesalePurchasePlanService
{
    public function __construct(
        private readonly RetailReorderIntelligenceService $reorder,
        private readonly B2bAccountLedgerService $ledger,
        private readonly B2bPriceResolver $pricing,
        private readonly WholesalePrincipal $principal,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $recommendations
     * @return array<string,mixed>
     */
    public function previewFromRecommendations(
        array $recommendations,
        float $purchasingPower,
        ?float $requestedBudget = null,
        string $currency = 'EGP',
        float $existingCartCost = 0.0,
    ): array {
        $creditLimit = max(0.0, $purchasingPower);
        $existingCartCost = max(0.0, $existingCartCost);
        $availablePlanBudget = max(0.0, round($creditLimit - $existingCartCost, 3));
        $budget = $requestedBudget === null
            ? $availablePlanBudget
            : min($availablePlanBudget, max(0.0, $requestedBudget));
        $remaining = $budget;
        $rows = [];
        $ordered = collect($recommendations)
            ->sort(function (array $left, array $right): int {
                $priority = ((float) ($right['priority_score'] ?? 0))
                    <=> ((float) ($left['priority_score'] ?? 0));

                return $priority !== 0
                    ? $priority
                    : ((int) ($left['retail_product_id'] ?? 0))
                        <=> ((int) ($right['retail_product_id'] ?? 0));
            })
            ->values();

        foreach ($ordered as $row) {
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
            'currency' => strtoupper($currency),
            'purchasing_power' => round($creditLimit, 3),
            'existing_cart_cost' => round($existingCartCost, 3),
            'available_plan_budget' => round($availablePlanBudget, 3),
            'requested_budget' => $requestedBudget === null ? null : round(max(0.0, $requestedBudget), 3),
            'effective_budget' => round($budget, 3),
            'allocated_cost' => round($budget - $remaining, 3),
            'remaining_budget' => round($remaining, 3),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $recommendations
     * @return array<string,mixed>
     */
    public function previewForOwner(
        User $user,
        int $retailStoreId,
        array $recommendations,
        ?float $requestedBudget = null,
    ): array {
        $customer = $this->ownerCustomer($user, $retailStoreId);
        $principalStoreId = $this->principal->storeId();
        $finance = $this->ledger->summary($customer, $principalStoreId);
        $cartState = $this->existingCartState($customer, $principalStoreId, false);
        $sourceMismatchCount = collect($recommendations)
            ->filter(fn (array $row): bool => (bool) data_get($row, 'recommendation.is_executable', false))
            ->filter(fn (array $row): bool => (int) data_get($row, 'mapping.source_wholesale_store_id', 0) !== $principalStoreId)
            ->count();
        $eligible = collect($recommendations)
            ->filter(fn (array $row): bool => (int) data_get($row, 'mapping.source_wholesale_store_id', 0) === $principalStoreId)
            ->values()
            ->all();

        $preview = $this->previewFromRecommendations(
            $eligible,
            (float) $finance['purchasing_power'],
            $requestedBudget,
            (string) $finance['currency'],
            $cartState['review_required']
                ? (float) $finance['purchasing_power']
                : (float) $cartState['cost'],
        );

        $preview['principal_wholesale_store_id'] = $principalStoreId;
        $preview['existing_cart_cost'] = round((float) $cartState['cost'], 3);
        $preview['cart_review_required'] = (bool) $cartState['review_required'];
        $preview['source_store_mismatch_count'] = $sourceMismatchCount;

        return $preview;
    }

    /**
     * Recompute W4 and revalidate price, quantity rules and availability before
     * writing to the existing B2B cart. Quantities are cart target quantities,
     * so retrying the same reviewed plan is idempotent.
     *
     * @param  array<int|string,mixed>  $requestedQuantities
     * @return array<string,mixed>
     */
    public function apply(
        User $user,
        int $retailStoreId,
        array $requestedQuantities,
        ?float $requestedBudget = null,
    ): array {
        $customer = $this->ownerCustomer($user, $retailStoreId);
        $principalStoreId = $this->principal->storeId();
        $finance = $this->ledger->summary($customer, $principalStoreId);
        $purchasingPower = max(0.0, (float) $finance['purchasing_power']);
        $cartState = $this->existingCartState($customer, $principalStoreId, true);
        $availablePlanBudget = max(0.0, round($purchasingPower - (float) $cartState['cost'], 3));
        $effectiveBudget = $requestedBudget === null
            ? $availablePlanBudget
            : min($availablePlanBudget, max(0.0, $requestedBudget));
        $existingQuantities = $this->existingCartQuantities($customer, $principalStoreId);

        $fresh = $this->reorder->forStore($retailStoreId);
        $rows = collect($fresh['recommendations'])
            ->filter(fn (array $row): bool => (bool) data_get($row, 'recommendation.is_executable', false))
            ->keyBy(fn (array $row): int => (int) $row['retail_product_id']);

        $selected = [];
        $selectedIncrementalCost = 0.0;

        foreach ($requestedQuantities as $retailProductId => $rawQuantity) {
            $quantity = is_numeric($rawQuantity) ? (float) $rawQuantity : 0.0;
            if ($quantity <= 0) {
                continue;
            }

            $row = $rows->get((int) $retailProductId);
            if (! is_array($row)) {
                $this->fail('quantities', 'stale_recommendation');
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
                $this->fail('quantities', 'stale_quantity');
            }

            $storeId = (int) ($mapping['source_wholesale_store_id'] ?? 0);
            $productId = (int) ($mapping['source_wholesale_product_id'] ?? 0);
            if ($storeId <= 0 || $productId <= 0) {
                $this->fail('quantities', 'missing_source');
            }
            if ($storeId !== $principalStoreId) {
                $this->fail('quantities', 'non_principal_source');
            }

            $pricing = $this->pricing->resolve($customer, $principalStoreId, $productId);
            $this->assertValidQuantity(
                $quantity,
                (float) $pricing['minimum_quantity'],
                (float) $pricing['ordering_increment'],
            );
            $available = $this->liveAvailable($principalStoreId, $productId);
            if ($quantity > $available + 0.0001) {
                $this->fail('quantities', 'availability_changed');
            }

            $existingQuantity = (float) ($existingQuantities[$productId] ?? 0.0);
            $targetQuantity = max($existingQuantity, $quantity);
            $incrementalQuantity = max(0.0, $targetQuantity - $existingQuantity);
            $lineIncrementalCost = round($incrementalQuantity * (float) $pricing['price'], 3);
            $selectedIncrementalCost = round($selectedIncrementalCost + $lineIncrementalCost, 3);
            $selected[] = [
                'store_id' => $principalStoreId,
                'product_id' => $productId,
                'quantity' => round($quantity, 3),
            ];
        }

        if ($selected === []) {
            $this->fail('quantities', 'empty_selection');
        }

        if ($selectedIncrementalCost > $effectiveBudget + 0.0001) {
            $this->fail('budget', 'budget_exceeded');
        }

        $result = DB::transaction(function () use (
            $customer,
            $selected,
            $principalStoreId,
            $requestedBudget,
        ): array {
            $cart = Cart::query()
                ->where('store_id', $principalStoreId)
                ->where('b2b_customer_id', $customer->getKey())
                ->where('channel', 'b2b')
                ->lockForUpdate()
                ->first();

            if (! $cart instanceof Cart) {
                $cart = Cart::query()->create([
                    'store_id' => $principalStoreId,
                    'customer_id' => $customer->legacy_customer_id,
                    'b2b_customer_id' => $customer->getKey(),
                    'channel' => 'b2b',
                    'guest_token' => null,
                ]);
            }

            $items = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $baseCartCost = 0.0;
            foreach ($items as $item) {
                $this->assertActiveWholesaleProduct($principalStoreId, (int) $item->product_id);
                $pricing = $this->pricing->resolve($customer, $principalStoreId, (int) $item->product_id);
                $this->assertValidQuantity(
                    (float) $item->quantity,
                    (float) $pricing['minimum_quantity'],
                    (float) $pricing['ordering_increment'],
                );
                $available = $this->liveAvailable($principalStoreId, (int) $item->product_id);
                if ((float) $item->quantity > $available + 0.0001) {
                    $this->fail('quantities', 'existing_cart_invalid');
                }
                $baseCartCost = round($baseCartCost + ((float) $item->quantity * (float) $pricing['price']), 3);
            }

            $liveFinance = $this->ledger->summary($customer, $principalStoreId);
            $livePurchasingPower = max(0.0, (float) $liveFinance['purchasing_power']);
            $liveAvailablePlanBudget = max(0.0, round($livePurchasingPower - $baseCartCost, 3));
            $liveEffectiveBudget = $requestedBudget === null
                ? $liveAvailablePlanBudget
                : min($liveAvailablePlanBudget, max(0.0, $requestedBudget));
            $liveIncrementalCost = 0.0;
            $mutations = [];

            foreach ($selected as $line) {
                $this->assertActiveWholesaleProduct($principalStoreId, $line['product_id']);
                $pricing = $this->pricing->resolve($customer, $principalStoreId, $line['product_id']);
                $existingItem = $items->get($line['product_id']);
                $existingQuantity = $existingItem instanceof CartItem ? (float) $existingItem->quantity : 0.0;
                $targetQuantity = max($existingQuantity, (float) $line['quantity']);
                $this->assertValidQuantity(
                    $targetQuantity,
                    (float) $pricing['minimum_quantity'],
                    (float) $pricing['ordering_increment'],
                );
                $available = $this->liveAvailable($principalStoreId, $line['product_id']);
                if ($targetQuantity > $available + 0.0001) {
                    $this->fail('quantities', 'availability_changed');
                }

                $incrementalQuantity = max(0.0, $targetQuantity - $existingQuantity);
                $incrementalCost = round($incrementalQuantity * (float) $pricing['price'], 3);
                $liveIncrementalCost = round($liveIncrementalCost + $incrementalCost, 3);
                $mutations[] = [
                    'item' => $existingItem,
                    'product_id' => $line['product_id'],
                    'target_quantity' => round($targetQuantity, 3),
                    'unit_price' => (float) $pricing['price'],
                ];
            }

            if ($liveIncrementalCost > $liveEffectiveBudget + 0.0001) {
                $this->fail('budget', 'price_or_credit_changed');
            }

            $changedLines = 0;
            foreach ($mutations as $mutation) {
                $item = $mutation['item'];
                if (! $item instanceof CartItem) {
                    $item = new CartItem([
                        'cart_id' => $cart->getKey(),
                        'product_id' => $mutation['product_id'],
                    ]);
                }

                if (! $item->exists || abs((float) $item->quantity - (float) $mutation['target_quantity']) > 0.0001) {
                    $changedLines++;
                }
                $item->quantity = $mutation['target_quantity'];
                $item->unit_price_snapshot = $mutation['unit_price'];
                $item->save();
            }

            return [
                'cart_ids' => [(int) $cart->getKey()],
                'changed_lines' => $changedLines,
                'selected_cost' => $liveIncrementalCost,
                'existing_cart_cost' => $baseCartCost,
                'cart_total_after' => round($baseCartCost + $liveIncrementalCost, 3),
                'effective_budget' => round($liveEffectiveBudget, 3),
                'purchasing_power' => round($livePurchasingPower, 3),
                'currency' => (string) $liveFinance['currency'],
            ];
        }, 3);

        return [
            ...$result,
            'selected_lines' => count($selected),
        ];
    }

    private function ownerCustomer(User $user, int $retailStoreId): B2bCustomer
    {
        $customerId = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $retailStoreId)
            ->where('owner_user_id', $user->getKey())
            ->value('b2b_customer_id');

        if ($customerId === null) {
            abort(403, __('admin.b2c_dashboard.merchant_intelligence.plan.errors.owner_only'));
        }

        return B2bCustomer::query()->findOrFail((int) $customerId);
    }

    /** @return array{cost:float,review_required:bool} */
    private function existingCartState(B2bCustomer $customer, int $storeId, bool $strict): array
    {
        $cart = Cart::query()
            ->where('store_id', $storeId)
            ->where('b2b_customer_id', $customer->getKey())
            ->where('channel', 'b2b')
            ->first();

        if (! $cart instanceof Cart) {
            return ['cost' => 0.0, 'review_required' => false];
        }

        $items = CartItem::query()->where('cart_id', $cart->getKey())->get();
        if ($items->isEmpty()) {
            return ['cost' => 0.0, 'review_required' => false];
        }

        $productIds = $items
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        try {
            $targets = array_map(
                static fn (int $productId): array => [
                    'store_id' => $storeId,
                    'product_id' => $productId,
                ],
                $productIds,
            );
            $prices = $this->pricing->resolveMany($customer, $targets);
            $availability = $this->liveAvailableMany($storeId, $productIds);

            $cost = 0.0;
            foreach ($items as $item) {
                $productId = (int) $item->product_id;
                $pricing = $prices[$storeId.':'.$productId] ?? null;
                if (! is_array($pricing)) {
                    throw new \RuntimeException('cart product or pricing unavailable');
                }

                $this->assertValidQuantity(
                    (float) $item->quantity,
                    (float) $pricing['minimum_quantity'],
                    (float) $pricing['ordering_increment'],
                );
                $available = (float) ($availability[$productId] ?? 0.0);
                if ((float) $item->quantity > $available + 0.0001) {
                    throw new \RuntimeException('cart availability drift');
                }
                $cost = round($cost + ((float) $item->quantity * (float) $pricing['price']), 3);
            }

            return ['cost' => $cost, 'review_required' => false];
        } catch (Throwable $exception) {
            if ($strict) {
                $this->fail('quantities', 'existing_cart_invalid');
            }

            return ['cost' => 0.0, 'review_required' => true];
        }
    }

    /** @return array<int,float> */
    private function existingCartQuantities(B2bCustomer $customer, int $storeId): array
    {
        $cartId = Cart::query()
            ->where('store_id', $storeId)
            ->where('b2b_customer_id', $customer->getKey())
            ->where('channel', 'b2b')
            ->value('id');

        if ($cartId === null) {
            return [];
        }

        return CartItem::query()
            ->where('cart_id', $cartId)
            ->pluck('quantity', 'product_id')
            ->map(static fn (mixed $quantity): float => (float) $quantity)
            ->all();
    }

    private function liveAvailable(int $storeId, int $productId): float
    {
        return (float) ($this->liveAvailableMany($storeId, [$productId])[$productId] ?? 0.0);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int,float>
     */
    private function liveAvailableMany(int $storeId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $productIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($productIds === []) {
            return [];
        }

        return DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->whereIn('inventories.product_id', $productIds)
            ->groupBy('inventories.product_id')
            ->get([
                'inventories.product_id',
                DB::raw('COALESCE(SUM(inventories.quantity - inventories.reserved_quantity), 0) as available'),
            ])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->product_id => round(max(0.0, (float) $row->available), 3),
            ])
            ->all();
    }

    private function assertActiveWholesaleProduct(int $storeId, int $productId): void
    {
        if ($storeId !== $this->principal->storeId()) {
            $this->fail('quantities', 'non_principal_source');
        }

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

        if (! $exists) {
            $this->fail('quantities', 'product_unavailable');
        }
    }

    private function assertValidQuantity(float $quantity, float $minimum, float $increment): void
    {
        $minimum = max(0.001, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity + 0.0001 < $minimum) {
            $this->fail('quantities', 'below_minimum');
        }

        $steps = ($quantity - $minimum) / $increment;
        if (abs($steps - round($steps)) >= 0.0001) {
            $this->fail('quantities', 'invalid_increment');
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

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([
            $field => [__('admin.b2c_dashboard.merchant_intelligence.plan.errors.'.$key)],
        ]);
    }
}
