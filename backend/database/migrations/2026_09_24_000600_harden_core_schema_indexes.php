<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->index(['store_type_id', 'is_active'], 'stores_type_active_idx');
        });

        Schema::table('user_store_roles', function (Blueprint $table): void {
            $table->index(['store_id', 'user_id'], 'user_store_roles_store_user_idx');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->index(['store_id', 'is_active'], 'warehouses_store_active_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['category_id', 'is_active'], 'products_category_active_idx');
            $table->index(['brand_id', 'is_active'], 'products_brand_active_idx');
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->index(['store_id', 'is_active'], 'store_products_store_active_idx');
        });

        Schema::table('inventories', function (Blueprint $table): void {
            $table->index('product_id', 'inventories_product_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['inventory_id', 'created_at'], 'stock_movements_inventory_created_idx');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->index(['store_id', 'channel'], 'carts_store_channel_idx');
            $table->index(['customer_id', 'channel'], 'carts_customer_channel_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['store_id', 'channel', 'status'], 'orders_store_channel_status_idx');
            $table->index(['customer_id', 'created_at'], 'orders_customer_created_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->index(['customer_id', 'status'], 'invoices_customer_status_idx');
        });

        Schema::table('promotions', function (Blueprint $table): void {
            $table->index(['store_id', 'is_active'], 'promotions_store_active_idx');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->index(
                ['driver_type', 'is_active', 'is_available'],
                'drivers_type_active_available_idx',
            );
        });

        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->index(['order_id', 'status'], 'driver_assignments_order_status_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['user_id', 'read_at'], 'notifications_user_read_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'audit_logs_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_user_created_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_user_read_idx');
        });

        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->dropIndex('driver_assignments_order_status_idx');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex('drivers_type_active_available_idx');
        });

        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropIndex('promotions_store_active_idx');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_customer_status_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_customer_created_idx');
            $table->dropIndex('orders_store_channel_status_idx');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex('carts_customer_channel_idx');
            $table->dropIndex('carts_store_channel_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_inventory_created_idx');
        });

        Schema::table('inventories', function (Blueprint $table): void {
            $table->dropIndex('inventories_product_idx');
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->dropIndex('store_products_store_active_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_brand_active_idx');
            $table->dropIndex('products_category_active_idx');
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex('warehouses_store_active_idx');
        });

        Schema::table('user_store_roles', function (Blueprint $table): void {
            $table->dropIndex('user_store_roles_store_user_idx');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex('stores_type_active_idx');
        });
    }
};
