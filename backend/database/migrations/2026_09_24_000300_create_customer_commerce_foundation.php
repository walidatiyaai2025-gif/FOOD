<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete(); $table->string('type')->default('b2c'); $table->string('name'); $table->string('phone')->nullable()->index(); $table->string('email')->nullable()->index(); $table->timestamps();
        });
        Schema::create('b2b_price_tiers', function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->unsignedInteger('priority')->default(0); $table->timestamps();
        });
        Schema::create('b2b_accounts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete(); $table->foreignId('price_tier_id')->nullable()->constrained('b2b_price_tiers')->nullOnDelete(); $table->string('company_name'); $table->string('status')->default('active'); $table->string('tax_number')->nullable(); $table->decimal('credit_limit',14,3)->default(0); $table->timestamps();
        });
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id(); $table->foreignId('customer_id')->constrained()->cascadeOnDelete(); $table->string('label')->nullable(); $table->string('line1'); $table->string('line2')->nullable(); $table->string('city'); $table->string('area')->nullable(); $table->string('country_code',2)->default('KW'); $table->decimal('latitude',10,7)->nullable(); $table->decimal('longitude',10,7)->nullable(); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create('carts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->constrained(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); $table->string('guest_token')->nullable()->index(); $table->string('channel')->default('b2c'); $table->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('cart_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained(); $table->decimal('quantity',14,3); $table->decimal('unit_price_snapshot',14,3)->nullable(); $table->timestamps(); $table->unique(['cart_id','product_id']);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->constrained(); $table->foreignId('customer_id')->constrained(); $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete(); $table->string('order_number')->unique(); $table->string('channel'); $table->string('status')->index(); $table->string('currency',3)->default('KWD'); $table->decimal('subtotal',14,3)->default(0); $table->decimal('discount_total',14,3)->default(0); $table->decimal('delivery_total',14,3)->default(0); $table->decimal('grand_total',14,3)->default(0); $table->timestamps();
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('order_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained(); $table->string('sku_snapshot'); $table->string('name_snapshot'); $table->decimal('quantity',14,3); $table->decimal('unit_price',14,3); $table->decimal('line_total',14,3); $table->timestamps();
        });
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id(); $table->foreignId('order_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('from_status')->nullable(); $table->string('to_status'); $table->text('note')->nullable(); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('order_status_history'); Schema::dropIfExists('order_items'); Schema::dropIfExists('orders'); Schema::dropIfExists('cart_items'); Schema::dropIfExists('carts'); Schema::dropIfExists('addresses'); Schema::dropIfExists('b2b_accounts'); Schema::dropIfExists('b2b_price_tiers'); Schema::dropIfExists('customers');
    }
};
