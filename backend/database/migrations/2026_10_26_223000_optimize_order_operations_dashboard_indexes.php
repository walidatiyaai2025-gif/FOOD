<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(
                ['store_id', 'channel', 'created_at', 'id'],
                'orders_ops_scope_created_idx',
            );
            $table->index(
                ['store_id', 'channel', 'status', 'created_at'],
                'orders_ops_scope_status_created_idx',
            );
        });

        Schema::table('order_dispatch_states', function (Blueprint $table): void {
            $table->index(
                ['status', 'order_id'],
                'order_dispatch_status_order_idx',
            );
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->index(
                ['order_id', 'to_status', 'created_at'],
                'order_history_order_status_created_idx',
            );
        });

        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->index(
                ['order_id', 'status', 'id'],
                'driver_assignments_order_status_id_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropIndex('driver_assignments_order_status_id_idx');
        });

        Schema::table('order_status_history', function (Blueprint $table): void {
            $table->dropIndex('order_history_order_status_created_idx');
        });

        Schema::table('order_dispatch_states', function (Blueprint $table): void {
            $table->dropIndex('order_dispatch_status_order_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_ops_scope_status_created_idx');
            $table->dropIndex('orders_ops_scope_created_idx');
        });
    }
};
