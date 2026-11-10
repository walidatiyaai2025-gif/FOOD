<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            // Nullable on legacy roles: none are retroactively classified as archived.
            // The existing unique roles.code remains a permanent identity reservation.
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        // A rollback must never erase tombstone state and silently make
        // retired role codes editable/reusable. Use verified recovery instead.
        if (DB::table('roles')->whereNotNull('archived_at')->exists()) {
            throw new \RuntimeException('Cannot drop role archival state while archived roles exist.');
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
