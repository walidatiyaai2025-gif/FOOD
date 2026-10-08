<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('retail_replenishment_items')
            || Schema::hasColumn('retail_replenishment_items', 'quantity_conversion_factor')
        ) {
            return;
        }

        Schema::table('retail_replenishment_items', function (Blueprint $table): void {
            $table->decimal('quantity_conversion_factor', 14, 3)
                ->default(1)
                ->after('quantity');
        });

        DB::table('retail_replenishment_items')
            ->orderBy('id')
            ->chunkById(250, function ($items): void {
                foreach ($items as $item) {
                    $sourceQuantity = (float) DB::table('order_items')
                        ->where('id', $item->source_order_item_id)
                        ->value('quantity');

                    if ($sourceQuantity <= 0) {
                        continue;
                    }

                    $factor = round((float) $item->quantity / $sourceQuantity, 3);

                    DB::table('retail_replenishment_items')
                        ->where('id', $item->id)
                        ->update([
                            'quantity_conversion_factor' => max(0.001, $factor),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('retail_replenishment_items')
            && Schema::hasColumn('retail_replenishment_items', 'quantity_conversion_factor')
        ) {
            Schema::table('retail_replenishment_items', function (Blueprint $table): void {
                $table->dropColumn('quantity_conversion_factor');
            });
        }
    }
};
