<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_saas_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->json('entitlements');
            $table->json('limits')->nullable();
            $table->string('billing_interval', 16)->default('monthly');
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'effective_from', 'effective_to'], 'rs_plan_effective_idx');
        });

        Schema::create('restaurant_saas_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->unique()
                ->constrained('enterprise_organizations')
                ->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('restaurant_saas_plans')->restrictOnDelete();
            $table->string('state', 24);
            $table->timestamp('effective_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->boolean('is_complimentary')->default(false);
            $table->string('complimentary_reason', 255)->nullable();
            $table->json('billing_contact')->nullable();
            $table->json('tax_profile')->nullable();
            $table->string('payment_method_reference', 160)->nullable();
            $table->timestamps();

            $table->index(['state', 'grace_ends_at'], 'rs_subscription_state_grace_idx');
            $table->index(['plan_id', 'state'], 'rs_subscription_plan_state_idx');
        });

        Schema::create('restaurant_saas_subscription_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('restaurant_saas_subscriptions')
                ->cascadeOnDelete();
            $table->string('code', 80);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'code'], 'rs_subscription_addon_unique');
            $table->index(['code', 'effective_to'], 'rs_addon_code_effective_idx');
        });

        Schema::create('restaurant_saas_usage_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('enterprise_organizations')
                ->cascadeOnDelete();
            $table->string('meter_key', 80);
            $table->unsignedBigInteger('quantity');
            $table->char('period_key', 7);
            $table->string('idempotency_key', 160)->unique();
            $table->string('source_reference', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['organization_id', 'meter_key', 'period_key'],
                'rs_usage_org_meter_period_idx',
            );
        });

        Schema::create('restaurant_saas_billing_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('restaurant_saas_subscriptions')
                ->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('status', 32);
            $table->string('provider', 64);
            $table->string('provider_reference', 160);
            $table->string('payment_method_reference', 160)->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_reference', 'kind'],
                'rs_billing_provider_document_unique',
            );
            $table->index(
                ['subscription_id', 'kind', 'status'],
                'rs_billing_subscription_state_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_saas_billing_records');
        Schema::dropIfExists('restaurant_saas_usage_meters');
        Schema::dropIfExists('restaurant_saas_subscription_addons');
        Schema::dropIfExists('restaurant_saas_subscriptions');
        Schema::dropIfExists('restaurant_saas_plans');
    }
};
