<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 160);
            $table->unsignedInteger('event_version');
            $table->longText('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'available_at', 'id'],
                'enterprise_outbox_due_idx',
            );
            $table->index(
                ['claim_token', 'status'],
                'enterprise_outbox_claim_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_outbox_messages');
    }
};
