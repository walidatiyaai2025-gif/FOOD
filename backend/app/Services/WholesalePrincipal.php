<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class WholesalePrincipal
{
    public const STORE_CODE = 'FOODEX-WHOLESALE';

    public function storeId(): int
    {
        return DB::transaction(function (): int {
            $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
            if ($typeId === null) {
                $typeId = DB::table('store_types')->insertGetId([
                    'code' => 'B2B',
                    'name' => 'Wholesale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $configuredCode = trim((string) config('foodex.platform_wholesale_store_code', ''));
            $canonicalCode = $configuredCode !== '' ? $configuredCode : self::STORE_CODE;

            $canonical = DB::table('stores')
                ->where('code', $canonicalCode)
                ->where('store_type_id', $typeId)
                ->first(['id', 'is_active']);

            if ($canonical !== null) {
                if (! (bool) $canonical->is_active) {
                    DB::table('stores')->where('id', $canonical->id)->update([
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                }

                return (int) $canonical->id;
            }

            $existing = DB::table('stores')
                ->where('store_type_id', $typeId)
                ->where('is_active', true)
                ->where('code', '!=', 'SYSTEM-LEGACY-QUARANTINE')
                ->orderBy('id')
                ->first(['id', 'code']);

            if ($existing !== null) {
                if ($configuredCode === '' && (string) $existing->code !== self::STORE_CODE) {
                    DB::table('stores')->where('id', $existing->id)->update([
                        'code' => self::STORE_CODE,
                        'updated_at' => now(),
                    ]);
                }

                return (int) $existing->id;
            }

            return (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => $canonicalCode,
                'name' => 'FOODEX Wholesale',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }
}
