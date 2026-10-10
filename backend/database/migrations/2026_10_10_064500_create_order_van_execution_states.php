<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_van_execution_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_van_assignment_id')->unique()->constrained('order_van_assignments')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('van_id')->constrained('vans')->restrictOnDelete();
            $table->string('status', 40)->default('assigned');
            $table->string('failure_reason_code', 80)->nullable();
            $table->text('failure_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->json('context')->nullable();
            $table->timestamp('last_transition_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'order_van_execution_order_lookup');
            $table->index(['van_id', 'status'], 'order_van_execution_van_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_van_execution_states');
    }
};
