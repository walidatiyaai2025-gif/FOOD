<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The v124 -> v125 updater runs migrations before rebuilding cached
        // configuration and before the BuiltInJobRoleSeeder child process.
        // Thus the new permission must be inserted independently of the
        // web process's potentially stale v124 config cache.
        DB::table('permissions')->insertOrIgnore([
            'code' => 'orders.customer_service_discount',
            'name' => 'Apply audited pre-dispatch percentage discounts with a mandatory reason',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain existing permission grants and historical audit references.
    }
};
