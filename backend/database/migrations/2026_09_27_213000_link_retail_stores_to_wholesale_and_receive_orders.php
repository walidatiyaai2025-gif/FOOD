<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_wholesale_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retail_store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('b2b_customer_id')->unique()->constrained('b2b_customers')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('retail_replenishments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retail_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('source_order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('source_wholesale_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('b2b_customer_id')->constrained('b2b_customers')->restrictOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['retail_store_id', 'received_at'], 'retail_replenishments_store_received_idx');
        });

        Schema::create('retail_replenishment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replenishment_id')->constrained('retail_replenishments')->cascadeOnDelete();
            $table->foreignId('source_order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('source_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('retail_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 14, 3);
            $table->decimal('line_total', 14, 3);
            $table->timestamps();

            $table->index(['replenishment_id', 'retail_product_id'], 'retail_replenishment_product_idx');
        });

        Schema::table('store_products', function (Blueprint $table): void {
            $table->decimal('cost_price', 14, 3)->nullable()->after('price');
        });

        $this->backfillRetailWholesaleAccounts();
    }

    public function down(): void
    {
        Schema::table('store_products', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });

        Schema::dropIfExists('retail_replenishment_items');
        Schema::dropIfExists('retail_replenishments');
        Schema::dropIfExists('retail_wholesale_accounts');
    }

    private function backfillRetailWholesaleAccounts(): void
    {
        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.name', 'stores.is_active']);

        foreach ($stores as $store) {
            $legacyCustomerId = (int) DB::table('customers')->insertGetId([
                'user_id' => null,
                'type' => 'b2b',
                'name' => (string) $store->name,
                'phone' => null,
                'email' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
                'legacy_customer_id' => $legacyCustomerId,
                'user_id' => null,
                'name' => (string) $store->name,
                'phone' => null,
                'email' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('b2b_accounts')->insert([
                'customer_id' => $legacyCustomerId,
                'b2b_customer_id' => $b2bCustomerId,
                'price_tier_id' => null,
                'company_name' => (string) $store->name,
                'status' => (bool) $store->is_active ? 'active' : 'suspended',
                'tax_number' => null,
                'credit_limit' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('retail_wholesale_accounts')->insert([
                'retail_store_id' => (int) $store->id,
                'b2b_customer_id' => $b2bCustomerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
