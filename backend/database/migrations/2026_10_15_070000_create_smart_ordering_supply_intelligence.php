<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_cart_items', function (Blueprint $table): void {
            $table->uuid('menu_item_public_id')->nullable()->after('product_id')->index();
        });

        Schema::create('smart_ordering_supply_mappings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->uuid('menu_item_public_id');
            $table->foreignId('variant_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('foodex_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_item', 16, 6);
            $table->decimal('waste_factor', 8, 6)->default(0);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'menu_item_public_id', 'is_active'], 'so_supply_mapping_item_idx');
            $table->index(['site_id', 'foodex_product_id', 'is_active'], 'so_supply_mapping_product_idx');
            $table->index(['branch_id', 'effective_from', 'effective_to'], 'so_supply_mapping_effective_idx');
        });

        Schema::create('smart_ordering_supply_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('foodex_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('on_hand_quantity', 16, 6);
            $table->string('source', 120);
            $table->timestamp('counted_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'branch_id', 'foodex_product_id'], 'so_supply_stock_scope_unique');
            $table->index(['site_id', 'foodex_product_id', 'counted_at'], 'so_supply_stock_lookup_idx');
        });

        Schema::create('smart_ordering_supply_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('foodex_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamp('dismissed_until');
            $table->string('reason', 500)->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'branch_id', 'dismissed_until'], 'so_supply_dismissal_scope_idx');
        });

        Schema::create('smart_ordering_supply_reorder_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->nullOnDelete();
            $table->foreignId('b2b_customer_id')->constrained('b2b_customers')->restrictOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $table->foreignId('converted_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->string('currency', 3)->default('EGP');
            $table->decimal('assisted_value', 16, 3)->default(0);
            $table->json('recommendation_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at'], 'so_supply_draft_site_idx');
            $table->index(['b2b_customer_id', 'cart_id'], 'so_supply_draft_cart_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_supply_reorder_drafts');
        Schema::dropIfExists('smart_ordering_supply_dismissals');
        Schema::dropIfExists('smart_ordering_supply_stock');
        Schema::dropIfExists('smart_ordering_supply_mappings');

        Schema::table('smart_ordering_cart_items', function (Blueprint $table): void {
            $table->dropColumn('menu_item_public_id');
        });
    }
};
