<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_app_settings', function (Blueprint $table): void {
            $table->id(); $table->string('app', 32); $table->string('environment', 32); $table->string('display_name');
            $table->string('android_package_id')->nullable(); $table->string('ios_bundle_id')->nullable();
            $table->string('published_version', 64)->nullable(); $table->string('published_build', 64)->nullable();
            $table->string('minimum_supported_version', 64)->nullable(); $table->string('recommended_version', 64)->nullable();
            $table->boolean('force_update')->default(false); $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message_ar')->nullable(); $table->text('maintenance_message_en')->nullable();
            $table->text('google_play_url')->nullable(); $table->text('app_store_url')->nullable();
            $table->text('privacy_url')->nullable(); $table->text('terms_url')->nullable(); $table->text('support_url')->nullable();
            $table->text('release_notes_ar')->nullable(); $table->text('release_notes_en')->nullable();
            $table->json('deep_link_config')->nullable(); $table->json('store_readiness')->nullable();
            $table->timestamps(); $table->unique(['app', 'environment']);
        });
        Schema::create('push_provider_settings', function (Blueprint $table): void {
            $table->id(); $table->string('app', 32); $table->string('platform', 16); $table->string('environment', 32);
            $table->string('provider', 32); $table->boolean('enabled')->default(false); $table->text('credentials_encrypted')->nullable();
            $table->string('default_sound')->nullable(); $table->string('default_channel')->nullable();
            $table->string('default_icon')->nullable(); $table->string('default_category')->nullable();
            $table->timestamps(); $table->unique(['app', 'platform', 'environment']);
        });
        Schema::create('push_device_tokens', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('app', 32);
            $table->string('platform', 16); $table->string('environment', 32); $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted'); $table->timestamp('revoked_at')->nullable(); $table->timestamps();
            $table->index(['app', 'platform', 'environment', 'revoked_at']);
        });
        Schema::create('push_delivery_logs', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('push_device_tokens')->nullOnDelete();
            $table->string('app', 32); $table->string('platform', 16); $table->string('environment', 32); $table->string('status', 32);
            $table->unsignedSmallInteger('response_code')->nullable(); $table->string('provider_message_id')->nullable();
            $table->string('error_code')->nullable(); $table->text('error_message')->nullable(); $table->boolean('is_test')->default(false);
            $table->timestamps(); $table->index(['app', 'environment', 'status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('push_delivery_logs'); Schema::dropIfExists('push_device_tokens');
        Schema::dropIfExists('push_provider_settings'); Schema::dropIfExists('mobile_app_settings');
    }
};
