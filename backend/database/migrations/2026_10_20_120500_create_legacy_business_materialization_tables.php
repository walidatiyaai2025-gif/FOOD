<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('legacy_business_table_manifest')) {
            Schema::create('legacy_business_table_manifest', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 64);
                $table->string('source_schema', 128);
                $table->string('source_table', 128);
                $table->string('domain', 64);
                $table->string('classification', 64);
                $table->boolean('business_impact')->default(true);
                $table->boolean('materialize')->default(true);
                $table->unsignedBigInteger('source_row_count')->default(0);
                $table->unsignedBigInteger('materialized_row_count')->default(0);
                $table->string('status', 32)->default('pending');
                $table->text('reason')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['source_system', 'source_schema', 'source_table'],
                    'legacy_business_manifest_identity_uq'
                );
                $table->index(
                    ['business_impact', 'status'],
                    'legacy_business_manifest_impact_status_idx'
                );
                $table->index(['domain'], 'legacy_business_manifest_domain_idx');
            });
        }

        if (! Schema::hasTable('legacy_business_records')) {
            Schema::create('legacy_business_records', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('source_system', 64);
                $table->string('source_schema', 128);
                $table->string('source_table', 128);
                $table->string('domain', 64);
                $table->unsignedBigInteger('source_row_ordinal');
                $table->text('source_key')->nullable();
                $table->char('row_hash', 64);
                $table->longText('payload');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['source_system', 'source_schema', 'source_table', 'source_row_ordinal'],
                    'legacy_business_record_identity_uq'
                );
                $table->index(
                    ['source_system', 'source_table'],
                    'legacy_business_record_table_idx'
                );
                $table->index(['domain'], 'legacy_business_record_domain_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_business_records');
        Schema::dropIfExists('legacy_business_table_manifest');
    }
};
