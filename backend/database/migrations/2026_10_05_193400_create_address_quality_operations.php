<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('address_quality_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 32)->default('unmapped');
            $table->string('quality_class', 32)->default('unknown');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('territory_key')->nullable();
            $table->string('resolution_source', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'address_quality_status_queue_idx');
            $table->index(['subject_type', 'subject_id'], 'address_quality_subject_idx');
            $table->index(['territory_key', 'status'], 'address_quality_territory_idx');
        });

        Schema::create('address_quality_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('address_quality_review_id')
                ->constrained('address_quality_reviews')
                ->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('old_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->string('old_territory_key')->nullable();
            $table->string('new_territory_key')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_quality_review_events');
        Schema::dropIfExists('address_quality_reviews');
    }
};
