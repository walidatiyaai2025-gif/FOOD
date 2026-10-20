<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_bi_profitability_facts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('source_event_id');
            $table->unsignedBigInteger('source_outbox_id');
            $table->char('source_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->dateTime('order_created_at');
            $table->string('order_status', 64);
            $table->string('channel', 16);
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_domain', 8);
            $table->unsignedBigInteger('domain_customer_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->char('currency', 3);
            $table->decimal('quantity', 18, 3);
            $table->decimal('gross_revenue', 18, 3);
            $table->decimal('discount_amount', 18, 3);
            $table->decimal('net_revenue', 18, 3);
            $table->decimal('unit_cost_snapshot', 18, 3)->nullable();
            $table->decimal('cost_total', 18, 3)->nullable();
            $table->decimal('margin', 18, 3)->nullable();
            $table->string('cost_state', 24);
            $table->unsignedBigInteger('cost_policy_id')->nullable();
            $table->timestamp('cost_policy_updated_at')->nullable();
            $table->dateTime('source_occurred_at');
            $table->timestamps();

            $table->unique(
                ['source_event_id', 'order_item_id'],
                'bi_profitability_event_item_unique',
            );
            $table->unique(
                ['store_id', 'order_id', 'order_item_id'],
                'bi_profitability_order_item_unique',
            );
            $table->index(
                ['store_id', 'order_created_at'],
                'bi_profitability_store_created_idx',
            );
            $table->index(
                ['store_id', 'product_id', 'order_created_at'],
                'bi_profitability_store_product_created_idx',
            );
            $table->index(
                ['store_id', 'customer_id', 'order_created_at'],
                'bi_profitability_store_customer_created_idx',
            );
            $table->index(
                ['store_id', 'channel', 'order_created_at'],
                'bi_profitability_store_channel_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_bi_profitability_facts');
    }
};
