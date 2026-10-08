<?php

namespace App\Domain\Pricing;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\User;
use App\Services\PlatformCustomerService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class B2bPriceResolver
{
    /** @return array{price: float, minimum_quantity: float, ordering_increment: float, pack_size: float, case_size: float|null, pack_label: string|null, retail_reference_price: float|null} */
    public function resolve(B2bCustomer $customer, int $storeId, int $productId): array
    {
        $key = $this->key($storeId, $productId);
        $resolved = $this->resolveMany($customer, [[
            'store_id' => $storeId,
            'product_id' => $productId,
        ]]);

        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        throw new HttpException(409, 'No approved B2B price exists for this product.');
    }

    /**
     * Resolve authoritative B2B pricing for many store/product pairs without
     * repeating account, tier and product queries per line.
     *
     * Missing products are omitted from the result so callers that operate on
     * many recommendations can mark only those rows as pricing-blocked.
     *
     * @param  list<array{store_id:int,product_id:int}>  $targets
     * @return array<string,array{price: float, minimum_quantity: float, ordering_increment: float, pack_size: float, case_size: float|null, pack_label: string|null, retail_reference_price: float|null}>
     */
    public function resolveMany(B2bCustomer $customer, array $targets): array
    {
        $targets = collect($targets)
            ->map(static fn (array $target): array => [
                'store_id' => (int) $target['store_id'],
                'product_id' => (int) $target['product_id'],
            ])
            ->filter(static fn (array $target): bool => $target['store_id'] > 0 && $target['product_id'] > 0)
            ->unique(fn (array $target): string => $this->key($target['store_id'], $target['product_id']))
            ->values();

        if ($targets->isEmpty()) {
            return [];
        }

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();

        $user = $customer->user_id === null ? null : User::query()->find($customer->user_id);
        $isPlatformCustomer = $user instanceof User
            && app(PlatformCustomerService::class)->isPlatformCustomer($user);

        if (! $account instanceof B2bAccount) {
            throw new HttpException(403, 'Approved B2B pricing account is required.');
        }

        if ($account->price_tier_id === null) {
            if ($isPlatformCustomer) {
                return $this->platformFallbackMany($targets->all());
            }

            throw new HttpException(403, 'Approved B2B pricing account is required.');
        }

        $groupedTargets = $targets->groupBy('store_id');
        $rules = DB::table('b2b_price_rules')
            ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.is_active', true)
            ->where('products.is_active', true)
            ->whereColumn('catalogs.store_id', 'b2b_price_rules.store_id')
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->where(function ($query) use ($groupedTargets): void {
                foreach ($groupedTargets as $storeId => $rows) {
                    $query->orWhere(function ($targetQuery) use ($storeId, $rows): void {
                        $targetQuery
                            ->where('b2b_price_rules.store_id', (int) $storeId)
                            ->whereIn(
                                'b2b_price_rules.product_id',
                                $rows->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                            );
                    });
                }
            })
            ->get([
                'b2b_price_rules.store_id',
                'b2b_price_rules.product_id',
                'b2b_price_rules.unit_price',
                'b2b_price_rules.minimum_quantity',
                'b2b_price_rules.ordering_increment',
                'b2b_price_rules.pack_size',
                'b2b_price_rules.case_size',
                'b2b_price_rules.pack_label',
                'b2b_price_rules.retail_reference_price',
            ]);

        $resolved = [];
        foreach ($rules as $rule) {
            $resolved[$this->key((int) $rule->store_id, (int) $rule->product_id)] = [
                'price' => (float) $rule->unit_price,
                'minimum_quantity' => (float) $rule->minimum_quantity,
                'ordering_increment' => max(0.001, (float) $rule->ordering_increment),
                'pack_size' => max(0.001, (float) $rule->pack_size),
                'case_size' => $rule->case_size === null ? null : (float) $rule->case_size,
                'pack_label' => $rule->pack_label === null ? null : (string) $rule->pack_label,
                'retail_reference_price' => $rule->retail_reference_price === null
                    ? null
                    : (float) $rule->retail_reference_price,
            ];
        }

        if (! $isPlatformCustomer) {
            return $resolved;
        }

        $missing = $targets
            ->filter(fn (array $target): bool => ! isset(
                $resolved[$this->key($target['store_id'], $target['product_id'])],
            ))
            ->values()
            ->all();

        return $resolved + $this->platformFallbackMany($missing);
    }

    /**
     * @param  list<array{store_id:int,product_id:int}>  $targets
     * @return array<string,array{price: float, minimum_quantity: float, ordering_increment: float, pack_size: float, case_size: float|null, pack_label: string|null, retail_reference_price: float|null}>
     */
    private function platformFallbackMany(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $groupedTargets = collect($targets)->groupBy('store_id');
        $fallbacks = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.is_active', true)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->where(function ($query) use ($groupedTargets): void {
                foreach ($groupedTargets as $storeId => $rows) {
                    $query->orWhere(function ($targetQuery) use ($storeId, $rows): void {
                        $targetQuery
                            ->where('catalogs.store_id', (int) $storeId)
                            ->whereIn(
                                'products.id',
                                $rows->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                            );
                    });
                }
            })
            ->get([
                'catalogs.store_id',
                'products.id as product_id',
                'store_products.price',
            ]);

        $resolved = [];
        foreach ($fallbacks as $fallback) {
            $resolved[$this->key((int) $fallback->store_id, (int) $fallback->product_id)] = [
                'price' => (float) $fallback->price,
                'minimum_quantity' => 1.0,
                'ordering_increment' => 1.0,
                'pack_size' => 1.0,
                'case_size' => null,
                'pack_label' => null,
                'retail_reference_price' => null,
            ];
        }

        return $resolved;
    }

    private function key(int $storeId, int $productId): string
    {
        return $storeId.':'.$productId;
    }
}
