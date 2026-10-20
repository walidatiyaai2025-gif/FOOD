<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_commerce_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('channel', 3);
            $table->date('fact_date');
            $table->unsignedInteger('orders_total')->default(0);
            $table->unsignedInteger('orders_recognized')->default(0);
            $table->decimal('gross_value', 18, 3)->default(0);
            $table->decimal('gross_revenue', 18, 3)->default(0);
            $table->decimal('discounts_total', 18, 3)->default(0);
            $table->decimal('net_revenue', 18, 3)->default(0);
            $table->decimal('refunded_value', 18, 3)->default(0);
            $table->unsignedInteger('refunded_orders')->default(0);
            $table->decimal('cancelled_value', 18, 3)->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->decimal('units_sold', 18, 3)->default(0);
            $table->decimal('average_order_value', 18, 3)->default(0);
            $table->decimal('payments_collected', 18, 3)->default(0);
            $table->decimal('payments_refunded', 18, 3)->default(0);
            $table->decimal('wholesale_purchases', 18, 3)->default(0);
            $table->decimal('wholesale_purchase_units', 18, 3)->default(0);
            $table->string('currency', 3)->nullable();
            $table->timestamp('source_max_updated_at')->nullable();
            $table->dateTime('materialized_at');
            $table->string('quality_state', 24)->default('complete');
            $table->string('incomplete_reason')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'channel', 'fact_date'], 'analytics_daily_store_channel_date_uq');
            $table->index(['channel', 'fact_date', 'store_id'], 'analytics_daily_channel_date_store_idx');
            $table->index(['store_id', 'fact_date'], 'analytics_daily_store_date_idx');
        });

        Schema::create('analytics_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('on_hand_units', 18, 3)->default(0);
            $table->decimal('reserved_units', 18, 3)->default(0);
            $table->decimal('available_units', 18, 3)->default(0);
            $table->unsignedInteger('sku_count')->default(0);
            $table->timestamp('source_max_updated_at')->nullable();
            $table->dateTime('materialized_at');
            $table->string('quality_state', 24)->default('complete');
            $table->string('incomplete_reason')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'snapshot_date'], 'analytics_inventory_store_date_uq');
            $table->index(['snapshot_date', 'store_id'], 'analytics_inventory_date_store_idx');
        });

        Schema::create('analytics_pipeline_watermarks', function (Blueprint $table): void {
            $table->id();
            $table->string('pipeline', 64);
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('channel', 8)->default('all');
            $table->date('materialized_through_date')->nullable();
            $table->timestamp('source_max_updated_at')->nullable();
            $table->dateTime('materialized_at');
            $table->unsignedInteger('rows_written')->default(0);
            $table->string('quality_state', 24)->default('complete');
            $table->string('incomplete_reason')->nullable();
            $table->timestamps();

            $table->unique(['pipeline', 'store_id', 'channel'], 'analytics_watermark_pipeline_store_channel_uq');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['store_id', 'channel', 'created_at'], 'orders_store_channel_created_idx');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['created_at', 'status', 'order_id'], 'payments_created_status_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_created_status_order_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_store_channel_created_idx');
        });

        Schema::dropIfExists('analytics_pipeline_watermarks');
        Schema::dropIfExists('analytics_inventory_snapshots');
        Schema::dropIfExists('analytics_daily_commerce_facts');
    }
};
