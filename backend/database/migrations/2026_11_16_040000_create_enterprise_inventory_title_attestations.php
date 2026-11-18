<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_inventory_title_attestations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_position_id')
                ->constrained('enterprise_inventory_owner_positions', 'id', 'eita_position_fk')
                ->restrictOnDelete();
            $table->char('stock_revision_sha256', 64);
            $table->string('document_kind', 36);
            $table->string('document_path', 190);
            $table->char('document_sha256', 64);
            $table->string('trusted_key_id', 64);
            $table->text('signature_base64');
            $table->foreignId('checked_by')->constrained('users', 'id', 'eita_checker_fk')->restrictOnDelete();
            $table->timestamp('signed_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            // New stock versions require new signed evidence. Two certificates
            // for one exact stock revision are prohibited, not auto-selected.
            $table->unique(['owner_position_id', 'stock_revision_sha256'], 'eita_position_revision_uq');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('enterprise_inventory_title_attestations')
            && DB::table('enterprise_inventory_title_attestations')->exists()) {
            throw new RuntimeException('Signed inventory legal-title history must be preserved before rollback.');
        }

        Schema::dropIfExists('enterprise_inventory_title_attestations');
    }
};
