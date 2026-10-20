<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_selling_unit_tier_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_selling_unit_id')->constrained('product_selling_units')->cascadeOnDelete();
            $table->foreignId('price_tier_id')->nullable()->constrained('b2b_price_tiers')->cascadeOnDelete();
            $table->unsignedTinyInteger('legacy_price_id');
            $table->decimal('price', 14, 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['store_id', 'product_selling_unit_id', 'legacy_price_id'],
                'selling_unit_tier_prices_legacy_uq',
            );
            $table->index(
                ['store_id', 'price_tier_id', 'product_selling_unit_id'],
                'selling_unit_tier_prices_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_selling_unit_tier_prices');
    }
};
