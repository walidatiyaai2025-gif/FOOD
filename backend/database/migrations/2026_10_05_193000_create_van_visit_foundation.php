<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('van_no_order_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('label_en', 160);
            $table->string('label_ar', 160);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('van_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('customer_type', 40);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('status', 40)->default('planned')->index();
            $table->foreignId('no_order_reason_id')->nullable()->constrained('van_no_order_reasons')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['customer_type', 'customer_id']);
            $table->index(['actor_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_visits');
        Schema::dropIfExists('van_no_order_reasons');
    }
};
