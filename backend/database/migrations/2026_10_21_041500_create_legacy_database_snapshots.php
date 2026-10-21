<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_database_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('source_database', 190);
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('table_count')->default(0);
            $table->unsignedBigInteger('source_row_count')->default(0);
            $table->unsignedBigInteger('archived_row_count')->default(0);
            $table->longText('summary')->nullable();
            $table->longText('error')->nullable();
            $table->dateTime('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_system', 'status', 'started_at'], 'legacy_db_snapshots_lookup_idx');
        });

        Schema::create('legacy_database_snapshot_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('snapshot_id');
            $table->string('source_schema', 128);
            $table->string('source_table', 128);
            $table->json('primary_key_columns')->nullable();
            $table->longText('column_metadata');
            $table->unsignedBigInteger('source_rows_start')->default(0);
            $table->unsignedBigInteger('source_rows_end')->nullable();
            $table->unsignedBigInteger('archived_rows')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('status', 24)->default('running');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(
                ['snapshot_id', 'source_schema', 'source_table'],
                'legacy_db_snapshot_table_uq',
            );
            $table->index(['snapshot_id', 'status'], 'legacy_db_snapshot_table_status_idx');
        });

        Schema::create('legacy_database_snapshot_chunks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('snapshot_id');
            $table->unsignedBigInteger('table_manifest_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedBigInteger('first_row_ordinal');
            $table->unsignedBigInteger('last_row_ordinal');
            $table->unsignedInteger('row_count');
            $table->unsignedBigInteger('raw_bytes');
            $table->unsignedBigInteger('compressed_bytes');
            $table->char('sha256', 64);
            $table->longText('payload_gzip_base64');
            $table->timestamps();

            $table->unique(
                ['table_manifest_id', 'chunk_index'],
                'legacy_db_snapshot_chunk_uq',
            );
            $table->index(['snapshot_id', 'table_manifest_id'], 'legacy_db_snapshot_chunk_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_database_snapshot_chunks');
        Schema::dropIfExists('legacy_database_snapshot_tables');
        Schema::dropIfExists('legacy_database_snapshots');
    }
};
