<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_carts', function (Blueprint $table): void {
            $table->json('merchandising_context')->nullable()->after('fulfillment_mode');
        });

        Schema::create('smart_ordering_offer_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->foreignId('marketing_coupon_id')->nullable()->constrained('marketing_coupons')->nullOnDelete();
            $table->string('name', 190);
            $table->string('type', 40);
            $table->integer('priority')->default(100);
            $table->string('stack_group', 80)->nullable();
            $table->boolean('exclusive')->default(true);
            $table->decimal('minimum_basket', 14, 3)->nullable();
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->json('placements')->nullable();
            $table->json('conditions')->nullable();
            $table->json('effect')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'branch_id', 'is_active'], 'so_offer_scope_active_idx');
            $table->index(['site_id', 'priority', 'id'], 'so_offer_precedence_idx');
        });

        Schema::create('smart_ordering_recommendation_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('name', 190);
            $table->string('placement', 50);
            $table->foreignId('trigger_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('recommended_product_id')->constrained('products', 'id', 'so_reco_recommended_product_fk')->cascadeOnDelete();
            $table->integer('priority')->default(100);
            $table->decimal('minimum_basket', 14, 3)->nullable();
            $table->json('conditions')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'placement', 'is_active'], 'so_reco_placement_idx');
        });

        Schema::create('smart_ordering_offer_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_rule_id')->constrained('smart_ordering_offer_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('discount_total', 14, 3)->default(0);
            $table->decimal('delivery_discount_total', 14, 3)->default(0);
            $table->timestamp('redeemed_at');
            $table->timestamps();
            $table->unique(['offer_rule_id', 'order_id'], 'so_offer_order_unique');
        });

        Schema::create('smart_ordering_merchandising_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->nullOnDelete();
            $table->foreignId('offer_rule_id')->nullable()->constrained('smart_ordering_offer_rules')->nullOnDelete();
            $table->foreignId('recommendation_rule_id')->nullable()->constrained('smart_ordering_recommendation_rules', 'id', 'so_merch_recommendation_rule_fk')->nullOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->uuid('cart_public_id')->nullable()->index();
            $table->string('event_type', 32);
            $table->string('placement', 50)->nullable();
            $table->decimal('revenue', 14, 3)->nullable();
            $table->json('metadata')->nullable();
            $table->string('event_key', 96)->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['site_id', 'event_type', 'occurred_at'], 'so_merch_event_rollup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_merchandising_events');
        Schema::dropIfExists('smart_ordering_offer_redemptions');
        Schema::dropIfExists('smart_ordering_recommendation_rules');
        Schema::dropIfExists('smart_ordering_offer_rules');

        Schema::table('smart_ordering_carts', function (Blueprint $table): void {
            $table->dropColumn('merchandising_context');
        });
    }
};
