<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_revision_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('storefront_revision_id')
                ->constrained('storefront_revisions')
                ->cascadeOnDelete();
            $table->string('asset_type', 32);
            $table->string('source_path', 1024);
            $table->char('source_path_hash', 64);
            $table->string('archive_path', 1024);
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(
                ['storefront_revision_id', 'source_path_hash'],
                'storefront_revision_asset_source_unique',
            );
            $table->index(['content_hash', 'asset_type'], 'storefront_revision_asset_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_revision_assets');
    }
};
