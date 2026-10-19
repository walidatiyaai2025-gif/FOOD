<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('legacy_finance_transactions')) {
            return;
        }

        DB::statement(
            "ALTER TABLE legacy_finance_transactions ".
            "MODIFY balance_effect VARCHAR(64) NOT NULL DEFAULT 'none'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('legacy_finance_transactions')) {
            return;
        }

        $tooLong = DB::table('legacy_finance_transactions')
            ->whereRaw('CHAR_LENGTH(balance_effect) > 16')
            ->exists();

        if ($tooLong) {
            throw new RuntimeException(
                'Cannot shrink legacy_finance_transactions.balance_effect to 16 characters while longer provenance values exist.'
            );
        }

        DB::statement(
            "ALTER TABLE legacy_finance_transactions ".
            "MODIFY balance_effect VARCHAR(16) NOT NULL DEFAULT 'none'"
        );
    }
};
