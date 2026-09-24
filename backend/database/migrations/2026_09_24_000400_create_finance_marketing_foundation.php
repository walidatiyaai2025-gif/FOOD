<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id(); $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->constrained(); $table->string('invoice_number')->unique(); $table->string('status')->default('issued'); $table->string('currency',3)->default('KWD'); $table->decimal('total',14,3)->default(0); $table->timestamp('issued_at')->nullable(); $table->timestamp('due_at')->nullable(); $table->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('invoice_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); $table->string('description'); $table->decimal('quantity',14,3); $table->decimal('unit_price',14,3); $table->decimal('line_total',14,3); $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); $table->string('provider'); $table->string('provider_reference')->nullable()->index(); $table->string('status')->index(); $table->decimal('amount',14,3); $table->string('currency',3)->default('KWD'); $table->json('metadata')->nullable(); $table->timestamps();
        });
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete(); $table->string('name'); $table->string('type'); $table->decimal('value',14,3)->nullable(); $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id(); $table->foreignId('promotion_id')->constrained()->cascadeOnDelete(); $table->string('code')->unique(); $table->unsignedInteger('usage_limit')->nullable(); $table->unsignedInteger('used_count')->default(0); $table->timestamps();
        });
        Schema::create('banners', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete(); $table->string('title'); $table->string('image_path'); $table->string('target_url')->nullable(); $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_active')->default(true); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('banners'); Schema::dropIfExists('coupons'); Schema::dropIfExists('promotions'); Schema::dropIfExists('payments'); Schema::dropIfExists('invoice_items'); Schema::dropIfExists('invoices');
    }
};
