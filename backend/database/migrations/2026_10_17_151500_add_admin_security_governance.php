<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('admin_mfa_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->json('recovery_codes')->nullable();
            $table->timestamp('enabled_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_security_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('mfa_verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at', 'last_seen_at'], 'admin_security_session_user_state_idx');
        });

        Schema::create('enterprise_sso_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('subject', 191);
            $table->string('email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'subject'], 'enterprise_sso_provider_subject_uq');
            $table->unique(['user_id', 'provider'], 'enterprise_sso_user_provider_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_sso_identities');
        Schema::dropIfExists('admin_security_sessions');
        Schema::dropIfExists('admin_mfa_credentials');
    }
};
