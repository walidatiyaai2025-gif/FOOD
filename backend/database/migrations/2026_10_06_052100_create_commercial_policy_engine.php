<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_commercial_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('OPEN');
            $table->boolean('hide_when_closed')->default(false);
            $table->boolean('override_allowed')->default(false);
            $table->json('channels')->nullable();
            $table->decimal('default_max_per_order', 14, 3)->nullable();
            $table->decimal('default_max_per_day', 14, 3)->nullable();
            $table->decimal('default_max_per_week', 14, 3)->nullable();
            $table->decimal('default_max_per_month', 14, 3)->nullable();
            $table->decimal('default_max_lifetime', 14, 3)->nullable();
            $table->string('business_timezone', 64)->default('UTC');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->timestamps();
        });

        Schema::create('product_selling_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->decimal('conversion_factor', 14, 3)->default(1);
            $table->decimal('price', 14, 3)->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'code'], 'product_selling_units_product_code_unique');
            $table->index(['product_id', 'is_active'], 'product_selling_units_product_active_idx');
        });

        Schema::create('product_availability_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('recurrence', 20)->default('fixed');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedTinyInteger('start_day')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->unsignedTinyInteger('end_day')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active'], 'product_availability_product_active_idx');
        });

        Schema::create('commercial_customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'commercial_groups_store_active_idx');
        });

        Schema::create('commercial_customer_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->constrained('commercial_customer_groups')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_group_id', 'customer_id'], 'commercial_group_customer_unique');
        });

        Schema::create('product_commercial_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained('commercial_customer_groups')->cascadeOnDelete();
            $table->string('channel', 30)->nullable();
            $table->boolean('is_allowed')->nullable();
            $table->decimal('max_per_order', 14, 3)->nullable();
            $table->decimal('max_per_day', 14, 3)->nullable();
            $table->decimal('max_per_week', 14, 3)->nullable();
            $table->decimal('max_per_month', 14, 3)->nullable();
            $table->decimal('max_lifetime', 14, 3)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'channel'], 'product_commercial_rules_product_channel_idx');
            $table->index(['product_id', 'customer_id'], 'product_commercial_rules_product_customer_idx');
            $table->index(['product_id', 'customer_group_id'], 'product_commercial_rules_product_group_idx');
        });

        Schema::create('commercial_quota_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'customer_id'], 'commercial_quota_lock_unique');
        });

        Schema::create('commercial_quota_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reservation_token')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_quantity', 14, 3);
            $table->string('status', 20)->default('RESERVED');
            $table->timestamp('reserved_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'product_id'], 'commercial_quota_order_product_unique');
            $table->index(
                ['customer_id', 'product_id', 'status', 'reserved_at'],
                'commercial_quota_usage_idx',
            );
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('selling_unit_code_snapshot', 80)->nullable();
            $table->string('selling_unit_name_snapshot')->nullable();
            $table->decimal('selling_unit_quantity', 14, 3)->nullable();
            $table->decimal('base_quantity', 14, 3)->nullable();
            $table->decimal('conversion_factor_snapshot', 14, 3)->nullable();
            $table->string('selling_unit_sku_snapshot')->nullable();
            $table->string('selling_unit_barcode_snapshot')->nullable();
        });

        $products = DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->orderBy('products.id')
            ->cursor();

        foreach ($products as $product) {
            DB::table('product_selling_units')->insert([
                'product_id' => $product->id,
                'unit_id' => $product->unit_id,
                'code' => $product->code,
                'name' => $product->name,
                'conversion_factor' => 1,
                'price' => null,
                'sku' => $product->sku,
                'barcode' => null,
                'is_base' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn([
                'selling_unit_code_snapshot',
                'selling_unit_name_snapshot',
                'selling_unit_quantity',
                'base_quantity',
                'conversion_factor_snapshot',
                'selling_unit_sku_snapshot',
                'selling_unit_barcode_snapshot',
            ]);
        });

        Schema::dropIfExists('commercial_quota_reservations');
        Schema::dropIfExists('commercial_quota_locks');
        Schema::dropIfExists('product_commercial_rules');
        Schema::dropIfExists('commercial_customer_group_members');
        Schema::dropIfExists('commercial_customer_groups');
        Schema::dropIfExists('product_availability_windows');
        Schema::dropIfExists('product_selling_units');
        Schema::dropIfExists('product_commercial_policies');
    }
};
