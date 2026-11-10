<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
