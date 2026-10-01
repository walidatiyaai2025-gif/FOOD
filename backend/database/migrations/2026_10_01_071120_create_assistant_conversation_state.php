<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversation_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('last_intent', 96)->nullable();
            $table->json('last_period')->nullable();
            $table->json('last_authorized_entities')->nullable();
            $table->string('last_tool', 96)->nullable();
            $table->json('result_references')->nullable();
            $table->json('pending_clarification_slots')->nullable();
            $table->string('locale', 8)->default('ar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversation_state');
    }
};
