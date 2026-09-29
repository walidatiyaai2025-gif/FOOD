<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'quote_id')) {
                $table->string('quote_id', 36)->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'quoted_at')) {
                $table->timestamp('quoted_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'tax_total')) {
                $table->decimal('tax_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('orders', 'b2b_account_id_snapshot')) {
                $table->unsignedBigInteger('b2b_account_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('orders', 'price_tier_id_snapshot')) {
                $table->unsignedBigInteger('price_tier_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('orders', 'price_tier_code_snapshot')) {
                $table->string('price_tier_code_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('orders', 'pricing_snapshot')) {
                $table->json('pricing_snapshot')->nullable();
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'quantity_conversion_factor')) {
                $table->decimal('quantity_conversion_factor', 14, 3)->default(1);
            }
            if (! Schema::hasColumn('order_items', 'base_unit_price_snapshot')) {
                $table->decimal('base_unit_price_snapshot', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'line_discount_total')) {
                $table->decimal('line_discount_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('order_items', 'line_tax_total')) {
                $table->decimal('line_tax_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('order_items', 'currency')) {
                $table->string('currency', 3)->default('EGP');
            }
            if (! Schema::hasColumn('order_items', 'b2b_account_id_snapshot')) {
                $table->unsignedBigInteger('b2b_account_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'price_tier_id_snapshot')) {
                $table->unsignedBigInteger('price_tier_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('order_items', 'price_tier_code_snapshot')) {
                $table->string('price_tier_code_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'minimum_quantity_snapshot')) {
                $table->decimal('minimum_quantity_snapshot', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'ordering_increment_snapshot')) {
                $table->decimal('ordering_increment_snapshot', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'pack_size_snapshot')) {
                $table->decimal('pack_size_snapshot', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'case_size_snapshot')) {
                $table->decimal('case_size_snapshot', 14, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        $orderColumns = array_values(array_filter([
            'quote_id',
            'quoted_at',
            'tax_total',
            'b2b_account_id_snapshot',
            'price_tier_id_snapshot',
            'price_tier_code_snapshot',
            'pricing_snapshot',
        ], static fn (string $column): bool => Schema::hasColumn('orders', $column)));

        if ($orderColumns !== []) {
            Schema::table('orders', function (Blueprint $table) use ($orderColumns): void {
                $table->dropColumn($orderColumns);
            });
        }

        $itemColumns = array_values(array_filter([
            'base_unit_price_snapshot',
            'line_discount_total',
            'line_tax_total',
            'currency',
            'b2b_account_id_snapshot',
            'price_tier_id_snapshot',
            'price_tier_code_snapshot',
            'minimum_quantity_snapshot',
            'ordering_increment_snapshot',
            'pack_size_snapshot',
            'case_size_snapshot',
        ], static fn (string $column): bool => Schema::hasColumn('order_items', $column)));

        if ($itemColumns !== []) {
            Schema::table('order_items', function (Blueprint $table) use ($itemColumns): void {
                $table->dropColumn($itemColumns);
            });
        }
    }
};
