<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('legacy_invoice_source_records')) {
            return;
        }

        Schema::create('legacy_invoice_source_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('source_system', 64)->default('foodex_sqlserver');
            $table->string('source_schema', 128);
            $table->string('source_table', 128);
            $table->unsignedBigInteger('source_row_ordinal');
            $table->text('source_key')->nullable();
            $table->unsignedBigInteger('source_header_id')->nullable()->index();
            $table->string('header_key_name', 64)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            $table->char('row_hash', 64);
            $table->longText('payload');
            $table->timestamps();

            $table->unique(
                ['source_system', 'source_schema', 'source_table', 'source_row_ordinal'],
                'legacy_invoice_source_record_uq'
            );
            $table->index(
                ['invoice_id', 'source_table'],
                'legacy_invoice_source_invoice_table_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_invoice_source_records');
    }
};
