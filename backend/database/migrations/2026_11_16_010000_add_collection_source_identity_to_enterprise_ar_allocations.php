<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'enterprise_ar_allocations';

    private const SOURCE = 'source_collection_allocation_id';

    // MySQL/MariaDB identifiers cannot exceed 64 characters. Laravel's
    // derived name for this long table+column exceeds that limit.
    private const FOREIGN_KEY = 'ear_alloc_coll_src_fk';

    private const UNIQUE_KEY = 'ear_alloc_collection_source_unique';

    public function up(): void
    {
        // MySQL DDL is not transactional: an earlier failed FK creation may
        // already have left the nullable column in place. Retry only the
        // missing schema pieces; never backfill from editable receipt text.
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreignId(self::SOURCE)->nullable();
            });
        }

        // SQLite PRAGMA foreign_key_list does not expose constraint names.
        // Identify the canonical source FK by its actual column AND target,
        // not a driver-specific synthetic name or editable receipt reference.
        $hasForeignKey = false;
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (! in_array(self::SOURCE, (array) ($foreignKey['columns'] ?? []), true)) {
                continue;
            }
            if (($foreignKey['foreign_table'] ?? null) !== 'collection_allocations') {
                throw new RuntimeException('Unexpected foreign key on collection settlement source identity.');
            }
            $hasForeignKey = true;
            break;
        }

        if (! $hasForeignKey) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign(self::SOURCE, self::FOREIGN_KEY)
                    ->references('id')
                    ->on('collection_allocations')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_KEY)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(self::SOURCE, self::UNIQUE_KEY);
            });
        }
    }

    public function down(): void
    {
        // Only for unactivated upgrades. Never assume that live financial
        // postings or a MySQL DDL sequence can be rolled back atomically.
        if (! Schema::hasColumn(self::TABLE, self::SOURCE)) {
            return;
        }

        // Source-backed collection claims are immutable settlement provenance.
        // Refuse any rollback that would erase an occupied link. This check
        // must run before the first non-transactional MySQL DDL statement.
        if (DB::table(self::TABLE)->whereNotNull(self::SOURCE)->exists()) {
            throw new RuntimeException(
                'Refusing to drop enterprise_ar_allocations.source_collection_allocation_id while linked collection settlements exist.',
            );
        }

        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (! in_array(self::SOURCE, (array) ($foreignKey['columns'] ?? []), true)) {
                continue;
            }

            // SQLite does not support dropping a foreign key by name. Laravel
            // must rebuild the table using a column-based drop BEFORE the
            // source column can be dropped. MySQL uses the actual FK name.
            if (($foreignKey['foreign_table'] ?? null) !== 'collection_allocations') {
                throw new RuntimeException('Refusing to drop an unexpected collection source foreign key.');
            }
            $name = (string) ($foreignKey['name'] ?? '');
            if (DB::getDriverName() !== 'sqlite' && $name !== self::FOREIGN_KEY) {
                throw new RuntimeException('Refusing to drop an unexpected collection source foreign key name.');
            }
            Schema::table(self::TABLE, function (Blueprint $table) use ($name): void {
                if (DB::getDriverName() === 'sqlite') {
                    $table->dropForeign([self::SOURCE]);
                } else {
                    $table->dropForeign($name);
                }
            });
            break;
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
};
