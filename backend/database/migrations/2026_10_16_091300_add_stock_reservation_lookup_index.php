<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const INDEX = 'stock_movements_store_ref_inventory_idx';

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(
                ['store_id', 'reference_type', 'reference_id', 'inventory_id'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
