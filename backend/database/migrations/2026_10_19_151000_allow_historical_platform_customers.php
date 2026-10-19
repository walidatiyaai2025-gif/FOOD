<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasHistoricalRows = DB::table('platform_customers')
            ->whereNull('user_id')
            ->orWhereNull('email')
            ->exists();

        if ($hasHistoricalRows) {
            throw new RuntimeException(
                'Cannot make platform_customers.user_id/email required while historical imported Customer 360 identities exist.'
            );
        }

        Schema::table('platform_customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
