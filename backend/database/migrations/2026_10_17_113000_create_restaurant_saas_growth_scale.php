<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_saas_gift_cards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('enterprise_companies')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->text('code_ciphertext');
            $table->string('issuance_key', 128);
            $table->string('display_code', 24);
            $table->char('currency', 3);
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'issuance_key'], 'restaurant_gift_card_issue_idem_uq');
            $table->index(['organization_id', 'company_id', 'store_id', 'status'], 'restaurant_gift_card_scope_idx');
        });

        Schema::create('restaurant_saas_gift_card_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gift_card_id')->constrained('restaurant_saas_gift_cards')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('entry_type', 24);
            $table->decimal('amount', 14, 3);
            $table->string('reference', 128)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['gift_card_id', 'idempotency_key'], 'restaurant_gift_card_idem_uq');
            $table->index(['gift_card_id', 'created_at'], 'restaurant_gift_card_entry_idx');
        });

        Schema::create('restaurant_saas_lifecycle_journeys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('enterprise_companies')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('name', 120);
            $table->string('trigger_event', 120);
            $table->string('segment_key', 120)->nullable();
            $table->string('channel', 32);
            $table->boolean('consent_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('configuration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'company_id', 'store_id', 'is_active'], 'restaurant_journey_scope_idx');
        });

        Schema::create('restaurant_saas_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('restaurant_saas_lifecycle_journeys')->cascadeOnDelete();
            $table->string('event_key', 160);
            $table->string('subject_reference', 190);
            $table->string('status', 24);
            $table->string('reason_code', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['journey_id', 'event_key'], 'restaurant_journey_event_uq');
            $table->index(['journey_id', 'status', 'occurred_at'], 'restaurant_journey_event_status_idx');
        });

        Schema::create('restaurant_saas_franchise_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('scope_key', 80);
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('enterprise_companies')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('policy_key', 120);
            $table->json('policy_value');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'policy_key'], 'restaurant_franchise_policy_scope_uq');
            $table->index(['organization_id', 'company_id', 'store_id'], 'restaurant_franchise_policy_org_idx');
        });

        Schema::create('restaurant_saas_export_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->json('scope_manifest');
            $table->string('file_path', 500)->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->json('record_counts')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at'], 'restaurant_export_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_saas_export_requests');
        Schema::dropIfExists('restaurant_saas_franchise_policies');
        Schema::dropIfExists('restaurant_saas_lifecycle_events');
        Schema::dropIfExists('restaurant_saas_lifecycle_journeys');
        Schema::dropIfExists('restaurant_saas_gift_card_entries');
        Schema::dropIfExists('restaurant_saas_gift_cards');
    }
};
