<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class B2bPricingMode
{
    public const SETTING_KEY = 'b2b.pricing_tiers_enabled';

    public function tiersEnabled(int $storeId): bool
    {
        $raw = DB::table('settings')
            ->where('store_id', $storeId)
            ->where('key', self::SETTING_KEY)
            ->where('is_secret', false)
            ->value('value');

        if ($raw === null) {
            return false;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return filter_var($decoded, FILTER_VALIDATE_BOOL);
    }
}
