<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A prior interrupted deployment may have created this table before Laravel
        // recorded the migration. Never destroy production discount history.
        if (Schema::hasTable('order_customer_service_discounts')) {
            $requiredColumns = [
                'id', 'order_id', 'applied_by_user_id', 'idempotency_key',
                'revision', 'percentage', 'reason', 'prior_customer_service_discount',
                'customer_service_discount', 'discount_total_before',
                'discount_total_after', 'grand_total_before', 'grand_total_after',
                'order_status', 'created_at', 'updated_at',
            ];

            $missingColumns = array_values(array_filter(
                $requiredColumns,
                static fn (string $column): bool => ! Schema::hasColumn('order_customer_service_discounts', $column)
            ));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing order_customer_service_discounts table is incompatible; missing columns: '
                    . implode(', ', $missingColumns)
                    . '. Stop the upgrade and inspect the schema without deleting data.'
                );
            }

            // Columns alone do not establish a safe idempotent recovery.
            // A partial/manual table without the uniqueness and FK guarantees
            // must fail closed rather than silently recording this migration.
            $indexes = Schema::getIndexes('order_customer_service_discounts');
            foreach ([
                'order_cs_discount_idempotency' => ['order_id', 'idempotency_key'],
                'order_cs_discount_revision' => ['order_id', 'revision'],
            ] as $name => $columns) {
                $matching = array_filter($indexes, static fn (array $index): bool =>
                    $index['name'] === $name
                    && $index['unique'] === true
                    && $index['columns'] === $columns
                );
                if ($matching === []) {
                    throw new RuntimeException('Existing order_customer_service_discounts table is missing unique index '.$name);
                }
            }

            $foreignKeys = Schema::getForeignKeys('order_customer_service_discounts');
            foreach (['order_id' => 'orders', 'applied_by_user_id' => 'users'] as $column => $table) {
                $matching = array_filter($foreignKeys, static fn (array $foreignKey): bool =>
                    $foreignKey['columns'] === [$column]
                    && $foreignKey['foreign_table'] === $table
                    && $foreignKey['foreign_columns'] === ['id']
                );
                if ($matching === []) {
                    throw new RuntimeException('Existing order_customer_service_discounts table is missing foreign key '.$column.' -> '.$table);
                }
            }

            return;
        }

        Schema::create('order_customer_service_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('applied_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('revision');
            $table->decimal('percentage', 6, 3);
            $table->text('reason');
            $table->decimal('prior_customer_service_discount', 14, 3)->default(0);
            $table->decimal('customer_service_discount', 14, 3);
            $table->decimal('discount_total_before', 14, 3);
            $table->decimal('discount_total_after', 14, 3);
            $table->decimal('grand_total_before', 14, 3);
            $table->decimal('grand_total_after', 14, 3);
            $table->string('order_status', 32);
            $table->timestamps();

            $table->unique(['order_id', 'idempotency_key'], 'order_cs_discount_idempotency');
            $table->unique(['order_id', 'revision'], 'order_cs_discount_revision');
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: the table may predate this migration in
        // databases recovering from an interrupted release. Preserve audit data.
    }
};
