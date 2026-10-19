<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_action_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action_key', 120);
            $table->string('action_version', 24)->default('1.0');
            $table->string('status', 24)->default('draft');
            $table->string('channel', 8);
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('permission_code', 160);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->char('context_hash', 64);
            $table->char('idempotency_key', 64)->unique();
            $table->json('preview');
            $table->char('approved_payload_hash', 64)->nullable();
            $table->string('correlation_id', 80)->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['store_id', 'action_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_action_approvals');
    }
};
