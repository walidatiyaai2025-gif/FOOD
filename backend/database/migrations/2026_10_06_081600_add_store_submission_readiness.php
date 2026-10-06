<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mobile_app_settings', function (Blueprint $table): void {
            $table->string('footer_display_mode', 32)->default('persistent')->after('store_readiness');
            $table->text('delete_account_url')->nullable()->after('support_url');
        });

        Schema::create('mobile_store_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 32);
            $table->string('platform', 16);
            $table->string('environment', 32);
            $table->string('package_identifier')->nullable();
            $table->string('current_version', 64)->nullable();
            $table->string('current_build', 64)->nullable();
            $table->string('minimum_version', 64)->nullable();
            $table->string('recommended_version', 64)->nullable();
            $table->string('update_policy', 32)->default('optional');
            $table->text('store_url')->nullable();
            $table->text('privacy_url')->nullable();
            $table->text('terms_url')->nullable();
            $table->text('support_url')->nullable();
            $table->text('delete_account_url')->nullable();
            $table->text('release_notes_ar')->nullable();
            $table->text('release_notes_en')->nullable();
            $table->string('title')->nullable();
            $table->string('short_description', 255)->nullable();
            $table->text('full_description')->nullable();
            $table->string('category')->nullable();
            $table->text('keywords')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->json('asset_checklist')->nullable();
            $table->json('permission_declarations')->nullable();
            $table->json('privacy_checklist')->nullable();
            $table->json('manual_gaps')->nullable();
            $table->string('signing_readiness', 16)->default('BLOCKED');
            $table->string('firebase_readiness', 16)->default('BLOCKED');
            $table->string('apns_readiness', 16)->default('WARN');
            $table->string('deep_link_readiness', 16)->default('WARN');
            $table->string('production_environment_readiness', 16)->default('BLOCKED');
            $table->string('readiness_state', 16)->default('BLOCKED');
            $table->string('submission_status', 32)->default('NOT_READY');
            $table->timestamps();
            $table->unique(['app', 'platform', 'environment'], 'mobile_store_submission_scope_unique');
        });

        Schema::create('store_reviewer_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 32);
            $table->string('platform', 16);
            $table->string('environment', 32);
            $table->string('persona', 64);
            $table->string('identifier_type', 16)->default('email');
            $table->string('identifier');
            $table->text('secret_encrypted')->nullable();
            $table->json('context')->nullable();
            $table->text('reviewer_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('readiness_status', 16)->default('BLOCKED');
            $table->string('readiness_message')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['app', 'platform', 'environment', 'persona'],
                'store_reviewer_account_scope_unique'
            );
        });

        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 32)->default('customer');
            $table->string('status', 40)->default('REQUESTED');
            $table->string('verification_method', 32)->default('password');
            $table->text('block_reason')->nullable();
            $table->json('retention_context')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
        Schema::dropIfExists('store_reviewer_accounts');
        Schema::dropIfExists('mobile_store_submissions');

        Schema::table('mobile_app_settings', function (Blueprint $table): void {
            $table->dropColumn(['footer_display_mode', 'delete_account_url']);
        });
    }
};
