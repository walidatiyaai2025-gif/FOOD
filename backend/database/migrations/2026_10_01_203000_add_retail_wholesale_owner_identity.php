<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_wholesale_accounts', function (Blueprint $table): void {
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('b2b_customer_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('owner_user_id', 'retail_wholesale_accounts_owner_user_idx');
        });

        $managerRoleId = DB::table('roles')
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('is_active', true)
            ->value('id');

        if ($managerRoleId === null) {
            return;
        }

        DB::table('retail_wholesale_accounts')
            ->whereNull('owner_user_id')
            ->orderBy('id')
            ->chunkById(250, function ($links) use ($managerRoleId): void {
                foreach ($links as $link) {
                    $managerIds = DB::table('user_store_roles')
                        ->where('store_id', $link->retail_store_id)
                        ->where('role_id', $managerRoleId)
                        ->distinct()
                        ->pluck('user_id');

                    // Existing data only becomes explicit ownership when provenance is
                    // unambiguous. Every B2C_STORE_ADMIN still remains a manager.
                    if ($managerIds->count() !== 1) {
                        continue;
                    }

                    DB::table('retail_wholesale_accounts')
                        ->where('id', $link->id)
                        ->update([
                            'owner_user_id' => (int) $managerIds->first(),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('retail_wholesale_accounts', function (Blueprint $table): void {
            $table->dropIndex('retail_wholesale_accounts_owner_user_idx');
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
