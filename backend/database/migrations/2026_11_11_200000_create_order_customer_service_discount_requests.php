<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An interrupted upgrade must never erase or silently recreate reviews.
        if (Schema::hasTable('order_customer_service_discount_requests')) {
            foreach ([
                'id', 'order_id', 'requested_by_user_id', 'reviewed_by_user_id',
                'idempotency_key', 'percentage', 'reason', 'status', 'review_reason',
                'reviewed_at', 'created_at', 'updated_at',
            ] as $column) {
                if (! Schema::hasColumn('order_customer_service_discount_requests', $column)) {
                    throw new RuntimeException('Incomplete discount approval schema: '.$column);
                }
            }

            return;
        }

        Schema::create('order_customer_service_discount_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->decimal('percentage', 6, 3);
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key'], 'cs_discount_request_key');
            $table->index(['order_id', 'status'], 'cs_discount_request_pending');
        });
    }

    public function down(): void
    {
        // Approval history must survive interrupted upgrades and rollback.
    }
};
