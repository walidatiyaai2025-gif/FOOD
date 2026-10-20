<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('product_supplier_brand_sources')) {
            return;
        }

        Schema::table('product_supplier_brand_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_supplier_brand_sources', 'relation_source')) {
                $table->string('relation_source', 190)->nullable()->after('source_supplier_id');
            }

            if (! Schema::hasColumn('product_supplier_brand_sources', 'authority_rank')) {
                $table->unsignedTinyInteger('authority_rank')->default(1)->after('relation_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_supplier_brand_sources')) {
            return;
        }

        Schema::table('product_supplier_brand_sources', function (Blueprint $table): void {
            if (Schema::hasColumn('product_supplier_brand_sources', 'authority_rank')) {
                $table->dropColumn('authority_rank');
            }

            if (Schema::hasColumn('product_supplier_brand_sources', 'relation_source')) {
                $table->dropColumn('relation_source');
            }
        });
    }
};
