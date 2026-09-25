<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 32);
            $table->string('environment', 32);
            $table->string('display_name');
            $table->string('android_package_id')->nullable();
            $table->string('ios_bundle_id')->nullable();
            $table->string('recommended_version')->nullable();
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message_ar')->nullable();
            $table->text('maintenance_message_en')->nullable();
            $table->text('privacy_url')->nullable();
            $table->text('terms_url')->nullable();
            $table->text('support_url')->nullable();
            $table->text('release_notes_ar')->nullable();
            $table->text('release_notes_en')->nullable();
            $table->timestamps();
            $table->unique(['app', 'environment']);
        });
        Schema::create('push_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 32);
            $table->string('platform', 16);
            $table->string('environment', 32);
            $table->boolean('enabled')->default(false);
            $table->text('credentials_encrypted')->nullable();
            $table->string('default_sound')->nullable();
            $table->string('default_channel')->nullable();
            $table->timestamps();
            $table->unique(['app', 'platform', 'environment']);
        });
        Schema::create('push_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 32);
            $table->string('platform', 16);
            $table->string('environment', 32);
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_device_tokens');
        Schema::dropIfExists('push_provider_settings');
        Schema::dropIfExists('mobile_app_settings');
    }
};
