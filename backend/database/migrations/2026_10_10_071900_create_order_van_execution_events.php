<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_van_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_van_assignment_id')->constrained('order_van_assignments')->cascadeOnDelete();
            $table->foreignId('order_van_execution_state_id')->constrained('order_van_execution_states')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('van_id')->constrained('vans')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('from_status', 40);
            $table->string('to_status', 40);
            $table->string('proof_type', 40)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['order_van_assignment_id', 'idempotency_key'],
                'van_execution_assignment_idempotency_unique',
            );
            $table->index(['order_id', 'captured_at'], 'van_execution_order_timeline');
            $table->index(['van_id', 'captured_at'], 'van_execution_van_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_van_execution_events');
    }
};
