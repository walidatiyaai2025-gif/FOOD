<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_products', static function (Blueprint $table): void {
            $table->unsignedInteger('app_display_order')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('store_products', static function (Blueprint $table): void {
            $table->dropColumn('app_display_order');
        });
    }
};
