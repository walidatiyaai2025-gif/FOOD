<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->unsignedBigInteger('connection_profile_id')->index();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_call_id', 255)->unique();
            $table->string('direction', 24)->index();
            $table->string('status', 32)->default('ringing')->index();
            $table->string('from_phone', 32)->nullable()->index();
            $table->string('to_phone', 32)->nullable()->index();
            $table->longText('remote_sdp')->nullable();
            $table->longText('local_sdp')->nullable();
            $table->string('biz_opaque_callback_data', 512)->nullable();
            $table->timestamp('ringing_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status'], 'wa_calls_conversation_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_calls');
    }
};
