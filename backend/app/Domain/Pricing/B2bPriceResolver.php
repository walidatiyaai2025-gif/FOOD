<?php

namespace App\Domain\Pricing;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class B2bPriceResolver
{
    /** @return array{price: float, minimum_quantity: float} */
    public function resolve(B2bCustomer $customer, int $storeId, int $productId): array
    {
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();

        if (! $account instanceof B2bAccount || $account->price_tier_id === null) {
            throw new HttpException(403, 'Approved B2B pricing account is required.');
        }

        $rule = DB::table('b2b_price_rules')
            ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.store_id', $storeId)
            ->where('b2b_price_rules.product_id', $productId)
            ->where('b2b_price_rules.is_active', true)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first(['b2b_price_rules.unit_price', 'b2b_price_rules.minimum_quantity']);

        if ($rule === null) {
            throw new HttpException(409, 'No approved B2B price exists for this product.');
        }

        return ['price' => (float) $rule->unit_price, 'minimum_quantity' => (float) $rule->minimum_quantity];
    }
}
