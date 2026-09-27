<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'payments', 'invoices', 'refunds'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'currency')) {
                DB::table($table)->where('currency', 'KWD')->update(['currency' => 'EGP']);
            }
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')
                ->whereIn('key', ['currency', 'default_currency', 'store.currency', 'checkout.currency'])
                ->where(function ($query): void {
                    $query->where('value', 'KWD')
                        ->orWhere('value', json_encode('KWD'));
                })
                ->update(['value' => json_encode('EGP')]);
        }
    }

    public function down(): void
    {
        // Currency migration is intentionally not reversed: reverting application code
        // must not silently relabel Egyptian-pound financial records as Kuwaiti dinar.
    }
};
