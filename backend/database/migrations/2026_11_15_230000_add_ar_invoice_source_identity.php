<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'enterprise_ar_documents';

    private const SOURCE = 'source_invoice_id';

    private const UNIQUE_KEY = 'ear_doc_legal_invoice_source_uq';

    public function up(): void
    {
        // MySQL/MariaDB DDL is not transactional. After an interrupted deploy
        // the column may exist while its FK or unique index does not. Recreate
        // only the missing pieces; never reconstruct identity from invoice text.
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId(self::SOURCE)->nullable();
            });
        }

        if (! $this->hasSourceForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(self::SOURCE)
                    ->references('id')
                    ->on('invoices')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['legal_entity_id', self::SOURCE], self::UNIQUE_KEY);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            return;
        }

        // A non-null source identity is immutable financial provenance. Deny
        // rollback BEFORE dropping any FK, unique index, or column. This guard
        // also protects against a partially applied MySQL schema migration.
        if (DB::table(self::TABLE)->whereNotNull(self::SOURCE)->exists()) {
            throw new RuntimeException(
                'Refusing to drop enterprise_ar_documents.source_invoice_id while financial invoice source links exist.',
            );
        }

        if ($this->hasSourceForeignKey()) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropForeign([self::SOURCE]);
            });
        }

        if (Schema::hasIndex(self::TABLE, self::UNIQUE_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_KEY);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::SOURCE);
        });
    }

    private function hasSourceForeignKey(): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (strtolower((string) ($foreignKey['foreign_table'] ?? '')) === 'invoices'
                && in_array(self::SOURCE, (array) ($foreignKey['columns'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }
};
