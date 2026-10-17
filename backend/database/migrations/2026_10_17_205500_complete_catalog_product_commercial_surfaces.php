<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('lifecycle_state', 24)->default('active')->after('is_active')->index();
            $table->timestamp('lifecycle_effective_at')->nullable()->after('lifecycle_state');
            $table->timestamp('end_of_life_at')->nullable()->after('lifecycle_effective_at');
        });

        DB::table('products')
            ->where('is_active', false)
            ->update([
                'lifecycle_state' => 'paused',
                'lifecycle_effective_at' => now(),
            ]);

        Schema::create('product_attribute_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('code', 80);
            $table->string('data_type', 24);
            $table->string('label_ar');
            $table->string('label_en');
            $table->json('allowed_values')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['catalog_id', 'code'], 'product_attr_catalog_code_uq');
            $table->index(['catalog_id', 'category_id', 'is_active'], 'product_attr_scope_idx');
        });

        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_definition_id')->constrained('product_attribute_definitions')->cascadeOnDelete();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'attribute_definition_id'], 'product_attr_value_uq');
        });

        Schema::create('product_lifecycle_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24);
            $table->timestamp('effective_at');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'effective_at'], 'product_lifecycle_history_idx');
        });

        Schema::create('catalog_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->char('package_hash', 64);
            $table->string('status', 24);
            $table->json('counts_json')->nullable();
            $table->json('errors_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->json('result_json')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status', 'created_at'], 'catalog_import_runs_store_status_idx');
            $table->index(['catalog_id', 'package_hash'], 'catalog_import_runs_package_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_runs');
        Schema::dropIfExists('product_lifecycle_transitions');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attribute_definitions');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['lifecycle_state']);
            $table->dropColumn(['lifecycle_state', 'lifecycle_effective_at', 'end_of_life_at']);
        });
    }
};
