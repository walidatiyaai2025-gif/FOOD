<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->string('intent', 96)->nullable()->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('payload')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at'], 'assistant_messages_conversation_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
