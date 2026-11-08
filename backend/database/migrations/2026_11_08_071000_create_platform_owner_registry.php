<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An interrupted update can create this table before Laravel records
        // the migration. Never erase an already-provisioned owner identity.
        if (Schema::hasTable('platform_owner_registry')) {
            foreach (['id', 'user_id', 'provisioned_at', 'created_at', 'updated_at'] as $column) {
                if (! Schema::hasColumn('platform_owner_registry', $column)) {
                    throw new RuntimeException('Existing platform_owner_registry is missing column '.$column);
                }
            }

            $indexes = Schema::getIndexes('platform_owner_registry');
            $primary = array_filter($indexes, static fn (array $index): bool =>
                ($index['primary'] ?? false) === true && ($index['columns'] ?? []) === ['id']
            );
            $uniqueUser = array_filter($indexes, static fn (array $index): bool =>
                ($index['unique'] ?? false) === true && ($index['columns'] ?? []) === ['user_id']
            );
            if ($primary === [] || $uniqueUser === []) {
                throw new RuntimeException('Existing platform_owner_registry has incompatible primary/unique keys.');
            }

            $foreignKeys = Schema::getForeignKeys('platform_owner_registry');
            $userForeignKey = array_filter($foreignKeys, static fn (array $key): bool =>
                ($key['columns'] ?? []) === ['user_id']
                && ($key['foreign_table'] ?? '') === 'users'
                && ($key['foreign_columns'] ?? []) === ['id']
            );
            if ($userForeignKey === []) {
                throw new RuntimeException('Existing platform_owner_registry is missing the users foreign key.');
            }

            if (DB::table('platform_owner_registry')->where('id', '<>', 1)->exists()
                || DB::table('platform_owner_registry')->count() > 1
                || DB::table('platform_owner_registry as owner')
                    ->leftJoin('users as users', 'users.id', '=', 'owner.user_id')
                    ->whereNull('users.id')->exists()) {
                throw new RuntimeException('Existing platform_owner_registry contains invalid owner data.');
            }

            return;
        }

        Schema::create('platform_owner_registry', function (Blueprint $table): void {
            // Exactly one protected, internally provisioned identity.
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestamp('provisioned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: interrupted upgrades may have already
        // registered a live platform owner. Never destroy that identity.
    }
};
