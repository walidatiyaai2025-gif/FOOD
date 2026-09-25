<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('scope', 16)->default('global')->after('description');
            $table->boolean('is_system')->default(false)->after('scope');
            $table->boolean('is_active')->default(true)->after('is_system');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_at');
        });

        DB::table('roles')->whereIn('code', [
            'SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN', 'OPERATIONS', 'INVENTORY',
            'FINANCE', 'CUSTOMER_SUPPORT', 'B2C_DRIVER', 'B2B_DRIVER',
        ])->update(['is_system' => true]);

        DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->update(['scope' => 'store']);
        DB::table('roles')->whereIn('code', ['OPERATIONS', 'INVENTORY', 'FINANCE', 'CUSTOMER_SUPPORT'])->update(['scope' => 'both']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['deactivated_at', 'deactivation_reason']);
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['description', 'scope', 'is_system', 'is_active']);
        });
    }
};
