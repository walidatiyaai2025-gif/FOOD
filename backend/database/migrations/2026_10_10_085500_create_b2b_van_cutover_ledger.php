<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_van_cutover_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('b2b_van_cutover_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_van_cutover_run_id')
                ->constrained('b2b_van_cutover_runs')
                ->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('channel', 8);
            $table->json('before_state');
            $table->json('after_state');
            $table->json('rollback_state')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['b2b_van_cutover_run_id', 'order_id'],
                'b2b_van_cutover_snapshot_order_unique'
            );
            $table->index(['channel', 'rolled_back_at'], 'b2b_van_cutover_snapshot_channel_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_van_cutover_snapshots');
        Schema::dropIfExists('b2b_van_cutover_runs');
    }
};
