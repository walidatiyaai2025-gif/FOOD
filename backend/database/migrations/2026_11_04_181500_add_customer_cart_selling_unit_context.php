<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', static function (Blueprint $table): void {
            $table->string('selling_unit_code', 80)->nullable()->after('quantity');
            $table->decimal('selling_unit_quantity', 14, 3)->nullable()->after('selling_unit_code');
            $table->decimal('selling_unit_conversion_factor', 14, 3)->nullable()->after('selling_unit_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', static function (Blueprint $table): void {
            $table->dropColumn([
                'selling_unit_code',
                'selling_unit_quantity',
                'selling_unit_conversion_factor',
            ]);
        });
    }
};
