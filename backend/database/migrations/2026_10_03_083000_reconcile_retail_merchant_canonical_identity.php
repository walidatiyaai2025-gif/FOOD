<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_wholesale_accounts')) {
            return;
        }

        $managerRoleId = DB::table('roles')
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('is_active', true)
            ->value('id');

        DB::table('retail_wholesale_accounts')
            ->orderBy('id')
            ->chunkById(250, function ($links) use ($managerRoleId): void {
                foreach ($links as $link) {
                    $ownerUserId = $link->owner_user_id === null
                        ? null
                        : (int) $link->owner_user_id;

                    if ($ownerUserId === null && $managerRoleId !== null) {
                        $managerIds = DB::table('user_store_roles')
                            ->where('store_id', $link->retail_store_id)
                            ->where('role_id', $managerRoleId)
                            ->distinct()
                            ->pluck('user_id');

                        if ($managerIds->count() === 1) {
                            $ownerUserId = (int) $managerIds->first();

                            DB::table('retail_wholesale_accounts')
                                ->where('id', $link->id)
                                ->update([
                                    'owner_user_id' => $ownerUserId,
                                    'updated_at' => now(),
                                ]);
                        }
                    }

                    if ($ownerUserId === null) {
                        continue;
                    }

                    $user = DB::table('users')
                        ->where('id', $ownerUserId)
                        ->first(['id', 'name', 'email']);

                    $store = DB::table('stores')
                        ->where('id', $link->retail_store_id)
                        ->first(['id', 'name']);

                    $b2bCustomer = DB::table('b2b_customers')
                        ->where('id', $link->b2b_customer_id)
                        ->first(['id', 'legacy_customer_id', 'phone']);

                    if ($user === null || $store === null || $b2bCustomer === null) {
                        continue;
                    }

                    $email = strtolower(trim((string) $user->email));

                    DB::table('b2b_customers')
                        ->where('id', $b2bCustomer->id)
                        ->update([
                            'name' => (string) $store->name,
                            'email' => $email,
                            'updated_at' => now(),
                        ]);

                    if ($b2bCustomer->legacy_customer_id === null) {
                        continue;
                    }

                    $legacy = DB::table('customers')
                        ->where('id', $b2bCustomer->legacy_customer_id)
                        ->first(['id']);

                    if ($legacy === null) {
                        continue;
                    }

                    DB::table('customers')
                        ->where('id', $legacy->id)
                        ->update([
                            'name' => (string) $store->name,
                            'type' => 'b2b',
                            'email' => $email,
                            'updated_at' => now(),
                        ]);

                    if (! Schema::hasTable('platform_customers')) {
                        continue;
                    }

                    $platform = DB::table('platform_customers')
                        ->where('user_id', $ownerUserId)
                        ->first();

                    if ($platform !== null) {
                        DB::table('platform_customers')
                            ->where('id', $platform->id)
                            ->update([
                                'name' => (string) $user->name,
                                'phone' => $platform->phone ?? $b2bCustomer->phone,
                                'email' => $email,
                                'is_active' => true,
                                'updated_at' => now(),
                            ]);

                        $this->markPlatformCustomer($ownerUserId);

                        continue;
                    }

                    $legacyConflict = DB::table('platform_customers')
                        ->where('legacy_customer_id', $legacy->id)
                        ->where('user_id', '!=', $ownerUserId)
                        ->exists();

                    if ($legacyConflict) {
                        continue;
                    }

                    DB::table('platform_customers')->insert([
                        'user_id' => $ownerUserId,
                        'legacy_customer_id' => (int) $legacy->id,
                        'name' => (string) $user->name,
                        'phone' => $b2bCustomer->phone,
                        'email' => $email,
                        'origin_channel' => 'b2c',
                        'origin_store_id' => (int) $store->id,
                        'registration_source' => 'migration',
                        'registered_at' => now(),
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->markPlatformCustomer($ownerUserId);
                }
            });
    }

    public function down(): void
    {
        // Data reconciliation is intentionally non-destructive and idempotent.
    }

    private function markPlatformCustomer(int $userId): void
    {
        if (! Schema::hasColumn('users', 'is_platform_customer')) {
            return;
        }

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'is_platform_customer' => true,
                'updated_at' => now(),
            ]);
    }
};
