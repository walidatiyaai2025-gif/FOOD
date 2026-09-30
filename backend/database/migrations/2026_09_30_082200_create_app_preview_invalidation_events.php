<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_preview_invalidation_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name', 80);
            $table->string('channel', 8);
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->uuid('revision_public_id')->nullable();
            $table->string('revision_status', 16)->nullable();
            $table->char('checksum', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['store_id', 'channel', 'id'], 'preview_invalidation_scope_cursor_idx');
            $table->index(['event_name', 'id'], 'preview_invalidation_event_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_preview_invalidation_events');
    }
};
