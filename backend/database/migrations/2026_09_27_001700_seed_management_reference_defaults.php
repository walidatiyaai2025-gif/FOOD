<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        DB::table('units')->upsert([
            ['code' => 'PC', 'name' => 'Piece', 'decimal_places' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'KG', 'name' => 'Kilogram', 'decimal_places' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'G', 'name' => 'Gram', 'decimal_places' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'L', 'name' => 'Liter', 'decimal_places' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ML', 'name' => 'Milliliter', 'decimal_places' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PACK', 'name' => 'Pack', 'decimal_places' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BOX', 'name' => 'Box', 'decimal_places' => 0, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'decimal_places', 'updated_at']);

        DB::table('b2b_price_tiers')->upsert([
            ['code' => 'STANDARD', 'name' => 'Standard', 'priority' => 100, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'WHOLESALE', 'name' => 'Wholesale', 'priority' => 200, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'VIP', 'name' => 'VIP', 'priority' => 300, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'priority', 'updated_at']);
    }

    public function down(): void
    {
        $unitIds = DB::table('units')->whereIn('code', ['PC', 'KG', 'G', 'L', 'ML', 'PACK', 'BOX'])->pluck('id');
        foreach ($unitIds as $unitId) {
            if (! DB::table('products')->where('unit_id', $unitId)->exists()) {
                DB::table('units')->where('id', $unitId)->delete();
            }
        }

        $tierIds = DB::table('b2b_price_tiers')->whereIn('code', ['STANDARD', 'WHOLESALE', 'VIP'])->pluck('id');
        foreach ($tierIds as $tierId) {
            $usedByAccounts = DB::table('b2b_accounts')->where('price_tier_id', $tierId)->exists();
            $usedByRules = DB::table('b2b_price_rules')->where('price_tier_id', $tierId)->exists();

            if (! $usedByAccounts && ! $usedByRules) {
                DB::table('b2b_price_tiers')->where('id', $tierId)->delete();
            }
        }
    }
};
