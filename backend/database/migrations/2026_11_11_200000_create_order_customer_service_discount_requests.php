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

            $indexes = Schema::getIndexes('order_customer_service_discount_requests');
            $unique = array_filter($indexes, static fn (array $index): bool =>
                $index['name'] === 'cs_discount_request_key'
                && $index['unique'] === true
                && $index['columns'] === ['order_id', 'idempotency_key']
            );
            if ($unique === []) {
                throw new RuntimeException('Existing discount requests table is missing idempotency uniqueness.');
            }

            $foreignKeys = Schema::getForeignKeys('order_customer_service_discount_requests');
            foreach ([
                'order_id' => 'orders',
                'requested_by_user_id' => 'users',
                'reviewed_by_user_id' => 'users',
            ] as $column => $target) {
                $matching = array_filter($foreignKeys, static fn (array $foreignKey): bool =>
                    $foreignKey['columns'] === [$column]
                    && $foreignKey['foreign_table'] === $target
                    && $foreignKey['foreign_columns'] === ['id']
                );
                if ($matching === []) {
                    throw new RuntimeException('Existing discount requests table is missing safe foreign key: '.$column);
                }
            }

            return;
        }

        Schema::create('order_customer_service_discount_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('requested_by_user_id');
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->uuid('idempotency_key');
            $table->decimal('percentage', 6, 3);
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->text('review_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // MySQL's 64-byte identifier limit forbids Laravel's generated
            // foreign-key names for this long table/column combination.
            $table->foreign('order_id', 'csdr_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'csdr_requester_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by_user_id', 'csdr_reviewer_fk')
                ->references('id')->on('users')->restrictOnDelete();

            $table->unique(['order_id', 'idempotency_key'], 'cs_discount_request_key');
            $table->index(['order_id', 'status'], 'cs_discount_request_pending');
        });
    }

    public function down(): void
    {
        // Approval history must survive interrupted upgrades and rollback.
    }
};
