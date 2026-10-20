<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(
                ['created_at', 'status', 'channel', 'store_id'],
                'orders_reporting_created_status_channel_store_idx',
            );
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(
                ['order_id', 'product_id'],
                'order_items_reporting_order_product_idx',
            );
            $table->index(
                ['product_id', 'order_id'],
                'order_items_reporting_product_order_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_reporting_order_product_idx');
            $table->dropIndex('order_items_reporting_product_order_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_reporting_created_status_channel_store_idx');
        });
    }
};
