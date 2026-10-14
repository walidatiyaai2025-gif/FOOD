<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_connection_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('display_phone_number', 32);
            $table->string('normalized_phone_number', 24);
            $table->string('provider_type', 40);
            $table->string('provider_phone_number_id', 128)->nullable();
            $table->string('provider_account_id', 128)->nullable();
            $table->string('provider_app_id', 128)->nullable();
            $table->text('credential_secret')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->json('webhook_metadata')->nullable();
            $table->json('validation_metadata')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->string('health_status', 32)->default('NEVER_TESTED');
            $table->boolean('is_primary')->default(false);
            $table->boolean('receive_only')->default(false);
            $table->unsignedTinyInteger('primary_slot')->nullable()->unique();
            $table->unsignedBigInteger('configuration_revision')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->string('last_provider_error', 500)->nullable();
            $table->string('health_summary', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider_type', 'normalized_phone_number'], 'wa_profiles_provider_phone_unique');
            $table->index(['provider_type', 'status'], 'wa_profiles_provider_status_index');
            $table->index('is_primary', 'wa_profiles_primary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_connection_profiles');
    }
};
