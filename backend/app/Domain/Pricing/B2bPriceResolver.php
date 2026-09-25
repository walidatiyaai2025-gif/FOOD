<?php

namespace App\Domain\Pricing;

use App\Models\B2bAccount;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class B2bPriceResolver
{
    /** @return array{price: float, minimum_quantity: float} */
    public function resolve(Customer $customer, int $storeId, int $productId): array
    {
        $account = B2bAccount::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();

        if (! $account instanceof B2bAccount || $account->price_tier_id === null) {
            throw new HttpException(403, 'Approved B2B pricing account is required.');
        }

        $rule = DB::table('b2b_price_rules')
            ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
            ->where('b2b_price_rules.store_id', $storeId)
            ->where('b2b_price_rules.product_id', $productId)
            ->where('b2b_price_rules.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first(['b2b_price_rules.unit_price', 'b2b_price_rules.minimum_quantity']);

        if ($rule === null) {
            throw new HttpException(409, 'No approved B2B price exists for this product.');
        }

        return ['price' => (float) $rule->unit_price, 'minimum_quantity' => (float) $rule->minimum_quantity];
    }
}
