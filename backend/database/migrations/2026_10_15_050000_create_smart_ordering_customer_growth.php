<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_repeat_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('source_order_id')->constrained('orders')->cascadeOnDelete();
            $table->json('selected_items');
            $table->json('quote');
            $table->string('status', 24)->default('DRAFT');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'customer_id', 'created_at'], 'so_repeat_drafts_customer_idx');
        });

        Schema::create('smart_ordering_loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->decimal('points_per_currency_unit', 10, 4)->default(1);
            $table->decimal('minimum_spend', 14, 3)->default(0);
            $table->unsignedInteger('expiry_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['site_id', 'branch_id'], 'so_loyalty_rule_scope_unique');
        });

        Schema::create('smart_ordering_loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->unsignedBigInteger('lifetime_earned')->default(0);
            $table->unsignedBigInteger('lifetime_redeemed')->default(0);
            $table->timestamps();
            $table->unique(['site_id', 'customer_id'], 'so_loyalty_account_unique');
        });

        Schema::create('smart_ordering_loyalty_rewards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->unsignedInteger('points_cost');
            $table->json('payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'is_active']);
        });

        Schema::create('smart_ordering_loyalty_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('smart_ordering_loyalty_accounts')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('reward_id')->nullable()->constrained('smart_ordering_loyalty_rewards')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->bigInteger('points');
            $table->bigInteger('balance_after');
            $table->string('idempotency_key', 160)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'customer_id', 'created_at'], 'so_loyalty_ledger_customer_idx');
            $table->index(['order_id', 'type']);
        });

        Schema::create('smart_ordering_review_settings', function (Blueprint $table): void {
            $table->foreignId('site_id')->primary()->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->boolean('request_enabled')->default(true);
            $table->unsignedTinyInteger('low_rating_threshold')->default(2);
            $table->unsignedTinyInteger('external_review_min_rating')->default(4);
            $table->string('external_review_url', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('smart_ordering_feedback', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('feedback')->nullable();
            $table->boolean('external_review_offered')->default(false);
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->unique(['site_id', 'order_id'], 'so_feedback_order_unique');
            $table->index(['site_id', 'rating', 'submitted_at']);
        });

        Schema::create('smart_ordering_service_recovery_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('feedback_id')->unique()->constrained('smart_ordering_feedback')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('OPEN');
            $table->string('reason', 120)->default('LOW_RATING');
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'created_at'], 'so_recovery_case_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_service_recovery_cases');
        Schema::dropIfExists('smart_ordering_feedback');
        Schema::dropIfExists('smart_ordering_review_settings');
        Schema::dropIfExists('smart_ordering_loyalty_ledger');
        Schema::dropIfExists('smart_ordering_loyalty_rewards');
        Schema::dropIfExists('smart_ordering_loyalty_accounts');
        Schema::dropIfExists('smart_ordering_loyalty_rules');
        Schema::dropIfExists('smart_ordering_repeat_drafts');
    }
};
