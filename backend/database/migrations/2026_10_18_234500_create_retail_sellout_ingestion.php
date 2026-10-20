<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_sellout_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('source_type', 24);
            $table->string('provider', 64);
            $table->string('source_key', 128);
            $table->string('source_timezone', 64)->default('Asia/Kuwait');
            $table->string('status', 16)->default('active');
            $table->string('last_sync_state', 16)->nullable();
            $table->timestamp('last_sync_started_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_source_at')->nullable();
            $table->date('coverage_from')->nullable();
            $table->date('coverage_to')->nullable();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->unsignedBigInteger('rows_accepted_total')->default(0);
            $table->unsignedBigInteger('rows_rejected_total')->default(0);
            $table->unsignedBigInteger('rows_duplicate_total')->default(0);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['retailer_store_id', 'source_type', 'source_key'],
                'retail_sellout_connection_scope_uq',
            );
            $table->index(
                ['retailer_store_id', 'status', 'last_sync_at'],
                'retail_sellout_connection_store_status_idx',
            );
        });

        Schema::create('retail_sellout_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('retail_sellout_connections')->cascadeOnDelete();
            $table->string('external_sku', 128);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('mapped_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['connection_id', 'external_sku'], 'retail_sellout_sku_mapping_uq');
        });

        Schema::create('retail_sellout_ingest_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('retail_sellout_connections')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('status', 16)->default('processing');
            $table->unsignedInteger('rows_received')->default(0);
            $table->unsignedInteger('rows_accepted')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->date('coverage_from')->nullable();
            $table->date('coverage_to')->nullable();
            $table->decimal('quality_score', 5, 2)->nullable();
            $table->timestamp('source_max_at')->nullable();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'idempotency_key'], 'retail_sellout_run_idempotency_uq');
            $table->index(['connection_id', 'created_at'], 'retail_sellout_run_connection_created_idx');
        });

        Schema::create('retail_sellout_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('retail_sellout_connections')->cascadeOnDelete();
            $table->foreignId('retailer_store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('source_event_id', 128);
            $table->string('transaction_id', 128);
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('external_sku', 128);
            $table->string('event_type', 16);
            $table->dateTime('occurred_at');
            $table->date('business_date');
            $table->decimal('quantity', 18, 3);
            $table->decimal('gross_amount', 18, 3)->default(0);
            $table->decimal('discount_amount', 18, 3)->default(0);
            $table->decimal('net_amount', 18, 3)->default(0);
            $table->string('currency', 3);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'source_event_id'], 'retail_sellout_event_source_uq');
            $table->index(
                ['retailer_store_id', 'business_date', 'event_type'],
                'retail_sellout_event_store_date_type_idx',
            );
            $table->index(
                ['connection_id', 'business_date', 'transaction_id'],
                'retail_sellout_event_connection_date_tx_idx',
            );
        });

        Schema::create('retail_sellout_ingest_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('retail_sellout_ingest_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_event_id', 128)->nullable();
            $table->string('code', 64);
            $table->string('message', 255);
            $table->string('payload_hash', 64);
            $table->timestamps();

            $table->index(['run_id', 'row_number'], 'retail_sellout_rejection_run_row_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_sellout_ingest_rejections');
        Schema::dropIfExists('retail_sellout_events');
        Schema::dropIfExists('retail_sellout_ingest_runs');
        Schema::dropIfExists('retail_sellout_sku_mappings');
        Schema::dropIfExists('retail_sellout_connections');
    }
};
