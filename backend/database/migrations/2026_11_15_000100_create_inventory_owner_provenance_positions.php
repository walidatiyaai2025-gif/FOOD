<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_inventory_owner_positions', function (Blueprint $table): void {
            $table->id();
            // A parent inventory delete must never cascade-delete supplier/FOODEX
            // legal-owner history, even before signed proof is issued.
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->string('owner_key', 80);
            $table->string('owner_kind', 16);
            $table->foreignId('supplier_id')->nullable()->constrained('enterprise_suppliers')->restrictOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('reserved_quantity', 14, 3)->default(0);
            $table->timestamp('source_inventory_updated_at')->nullable();
            $table->string('evidence_type', 64)->nullable();
            $table->string('evidence_reference', 160)->nullable();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('certified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'owner_key'], 'eio_owner_inventory_key_uq');
            $table->index(['owner_kind', 'supplier_id'], 'eio_owner_kind_supplier_idx');
        });

        // Existing inventory has no legal-owner provenance. Preserve every
        // quantity and reservation as UNKNOWN; never silently assign FOODEX
        // title, a supplier identity, or an approved cost during upgrade.
        DB::table('inventories')->orderBy('id')->chunkById(250, static function ($rows): void {
            $now = now();
            $positions = [];
            foreach ($rows as $row) {
                $positions[] = [
                    'inventory_id' => $row->id,
                    'owner_key' => 'UNKNOWN',
                    'owner_kind' => 'UNKNOWN',
                    'supplier_id' => null,
                    'quantity' => $row->quantity,
                    'reserved_quantity' => $row->reserved_quantity,
                    'source_inventory_updated_at' => $row->updated_at,
                    'evidence_type' => null,
                    'evidence_reference' => null,
                    'proposed_by' => null,
                    'certified_by' => null,
                    'certified_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($positions !== []) {
                DB::table('enterprise_inventory_owner_positions')->insert($positions);
            }
        });
    }

    public function down(): void
    {
        // Owner allocations and legal-title assertions are audit data, even
        // when still UNKNOWN or UNVERIFIED. A downgrade must never silently
        // discard them before a verified backup/recovery plan exists.
        if (Schema::hasTable('enterprise_inventory_owner_positions')
            && DB::table('enterprise_inventory_owner_positions')->exists()) {
            throw new \RuntimeException('Inventory owner positions must be explicitly preserved before rollback.');
        }

        Schema::dropIfExists('enterprise_inventory_owner_positions');
    }
};
