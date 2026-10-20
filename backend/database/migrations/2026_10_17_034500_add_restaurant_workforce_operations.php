<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'user_id'], 'restaurant_staff_branch_user_unique');
            $table->index(['user_id', 'is_active'], 'restaurant_staff_user_active_idx');
        });

        Schema::create('restaurant_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 24)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'starts_at'], 'restaurant_shifts_branch_status_start_idx');
            $table->index(['user_id', 'starts_at'], 'restaurant_shifts_user_start_idx');
        });

        Schema::create('restaurant_time_clock_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('restaurant_shifts')->nullOnDelete();
            $table->string('status', 24)->default('OPEN');
            $table->timestamp('clocked_in_at');
            $table->timestamp('break_started_at')->nullable();
            $table->timestamp('last_break_ended_at')->nullable();
            $table->unsignedInteger('total_break_seconds')->default(0);
            $table->timestamp('clocked_out_at')->nullable();
            $table->foreignId('corrected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('correction_note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'clocked_in_at'], 'restaurant_clock_branch_status_in_idx');
            $table->index(['user_id', 'status'], 'restaurant_clock_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_time_clock_sessions');
        Schema::dropIfExists('restaurant_shifts');
        Schema::dropIfExists('restaurant_staff_assignments');
    }
};
