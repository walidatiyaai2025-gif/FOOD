<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_preview_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 24);
            $table->string('channel', 8);
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('mode', 24)->default('read_only');
            $table->boolean('support_access')->default(false);
            $table->string('token_hash', 64)->unique();
            $table->uuid('audit_correlation_id')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_resolved_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->index(['actor_user_id', 'channel', 'store_id'], 'preview_actor_scope_index');
            $table->index(['target_user_id', 'target_type'], 'preview_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_preview_sessions');
    }
};
