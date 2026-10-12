<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProductSkuGenerator
{
    public function generate(int $catalogId, int $storeId): string
    {
        do {
            $sku = sprintf('SKU-%d-%s', $storeId, Str::upper(Str::random(10)));
        } while (DB::table('products')
            ->where('catalog_id', $catalogId)
            ->where('sku', $sku)
            ->exists());

        return $sku;
    }
}
