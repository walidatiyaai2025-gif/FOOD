<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('legacy_source_archive_tables')) {
            Schema::create('legacy_source_archive_tables', function (Blueprint $table): void {
                $table->id();
                $table->string('source_system', 64);
                $table->string('source_schema', 128);
                $table->string('source_table', 128);
                $table->uuid('archive_run_id');
                $table->unsignedBigInteger('source_row_count')->default(0);
                $table->unsignedBigInteger('archived_row_count')->default(0);
                $table->json('primary_key_columns')->nullable();
                $table->json('source_columns')->nullable();
                $table->string('status', 32)->default('pending');
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['source_system', 'source_schema', 'source_table'],
                    'legacy_archive_table_identity_uq'
                );
                $table->index(['archive_run_id', 'status'], 'legacy_archive_table_run_status_idx');
            });
        }

        if (! Schema::hasTable('legacy_source_archive_rows')) {
            Schema::create('legacy_source_archive_rows', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('source_system', 64);
                $table->string('source_schema', 128);
                $table->string('source_table', 128);
                $table->uuid('archive_run_id');
                $table->unsignedBigInteger('source_row_ordinal');
                $table->text('source_key')->nullable();
                $table->char('row_hash', 64);
                $table->longText('payload');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(
                    ['source_system', 'source_schema', 'source_table', 'source_row_ordinal'],
                    'legacy_archive_row_ordinal_uq'
                );
                $table->index(
                    ['source_system', 'source_schema', 'source_table'],
                    'legacy_archive_row_table_idx'
                );
                $table->index(['archive_run_id'], 'legacy_archive_row_run_idx');
                $table->index(['row_hash'], 'legacy_archive_row_hash_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_source_archive_rows');
        Schema::dropIfExists('legacy_source_archive_tables');
    }
};
