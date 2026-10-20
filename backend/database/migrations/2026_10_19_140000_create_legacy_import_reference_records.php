<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_import_reference_records', function (Blueprint $table): void {
            $table->id();
            $table->string('source_system', 64);
            $table->string('source_table', 128);
            $table->string('source_key', 191);
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->uuid('last_run_id')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'source_table', 'source_key'], 'legacy_reference_source_unique');
            $table->index(['source_system', 'source_table'], 'legacy_reference_source_table_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_reference_records');
    }
};
