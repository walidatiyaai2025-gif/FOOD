<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_saas_integrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('connector_type', 40);
            $table->string('provider_key', 80);
            $table->string('display_name', 120);
            $table->string('status', 30)->default('disabled');
            $table->json('capabilities');
            $table->string('credential_reference', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'provider_key', 'branch_id'], 'restaurant_integration_scope_unique');
            $table->index(['site_id', 'connector_type', 'status'], 'restaurant_integration_health_idx');
        });

        Schema::create('restaurant_saas_integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('restaurant_saas_integrations')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('event_type', 120);
            $table->string('external_event_key', 190);
            $table->char('payload_hash', 64);
            $table->text('payload_ciphertext');
            $table->string('status', 30)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'direction', 'external_event_key'], 'restaurant_integration_event_unique');
            $table->index(['integration_id', 'status', 'created_at'], 'restaurant_integration_event_status_idx');
        });

        Schema::create('restaurant_saas_api_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64);
            $table->json('scopes');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'is_active', 'expires_at'], 'restaurant_api_client_scope_idx');
        });

        Schema::create('restaurant_saas_webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->string('target_url', 1000);
            $table->json('event_types');
            $table->text('secret_ciphertext');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'is_active'], 'restaurant_webhook_scope_idx');
        });

        Schema::create('restaurant_saas_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('restaurant_saas_webhook_subscriptions')->cascadeOnDelete();
            $table->string('event_key', 190);
            $table->string('event_type', 120);
            $table->char('payload_hash', 64);
            $table->text('payload_ciphertext');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'event_key'], 'restaurant_webhook_delivery_unique');
            $table->index(['status', 'scheduled_at'], 'restaurant_webhook_delivery_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_saas_webhook_deliveries');
        Schema::dropIfExists('restaurant_saas_webhook_subscriptions');
        Schema::dropIfExists('restaurant_saas_api_clients');
        Schema::dropIfExists('restaurant_saas_integration_events');
        Schema::dropIfExists('restaurant_saas_integrations');
    }
};
