<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flash_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('name');
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->string('timezone', 64)->default('Asia/Kuwait');
            $table->json('channels');
            $table->string('allocation_mode', 24)->default('shared');
            $table->decimal('total_allocation_base', 14, 3)->nullable();
            $table->decimal('per_customer_limit_base', 14, 3)->nullable();
            $table->unsignedInteger('reservation_seconds')->default(300);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->integer('priority')->default(0);
            $table->string('popup_frequency', 32)->default('once_per_session');
            $table->boolean('counts_toward_normal_quota')->default(true);
            $table->boolean('stackable')->default(false);
            $table->boolean('kill_switch')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['store_id', 'status', 'starts_at', 'ends_at'], 'flash_offer_store_lifecycle');
        });

        Schema::create('flash_offer_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flash_offer_id')->constrained('flash_offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('selling_unit_id')->nullable();
            $table->string('selling_unit_code', 64)->nullable();
            $table->decimal('conversion_factor', 14, 3)->default(1);
            $table->decimal('flash_price', 14, 3);
            $table->decimal('allocation_base', 14, 3)->nullable();
            $table->timestamps();
            $table->index(['flash_offer_id', 'product_id'], 'flash_offer_product_lookup');
        });

        Schema::create('flash_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('flash_offer_id')->constrained('flash_offers')->cascadeOnDelete();
            $table->foreignId('flash_offer_product_id')->constrained('flash_offer_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->decimal('selling_quantity', 14, 3);
            $table->decimal('reserved_base_quantity', 14, 3);
            $table->decimal('unit_price', 14, 3);
            $table->string('status', 24)->default('active')->index();
            $table->string('idempotency_key', 120);
            $table->json('inventory_allocations');
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'flash_reservation_idempotency');
            $table->index(['flash_offer_id', 'user_id', 'status'], 'flash_reservation_customer_state');
        });

        Schema::create('flash_offer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flash_offer_id')->constrained('flash_offers')->cascadeOnDelete();
            $table->uuid('flash_reservation_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48)->index();
            $table->string('channel', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['flash_offer_id', 'event', 'occurred_at'], 'flash_offer_event_analytics');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_offer_events');
        Schema::dropIfExists('flash_reservations');
        Schema::dropIfExists('flash_offer_products');
        Schema::dropIfExists('flash_offers');
    }
};
