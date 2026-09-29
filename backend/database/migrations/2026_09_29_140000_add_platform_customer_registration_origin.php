<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_customers', function (Blueprint $table): void {
            $table->string('origin_channel', 16)->nullable()->after('email')->index();
            $table->foreignId('origin_store_id')
                ->nullable()
                ->after('origin_channel')
                ->constrained('stores')
                ->restrictOnDelete();
            $table->string('registration_source', 40)->default('migration')->after('origin_store_id')->index();
            $table->timestamp('registered_at')->nullable()->after('registration_source')->index();
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->foreignId('default_customer_wholesale_price_tier_id')
                ->nullable()
                ->after('store_type_id')
                ->constrained('b2b_price_tiers')
                ->nullOnDelete();
        });

        $standardTierId = DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        if ($standardTierId === null) {
            $standardTierId = (int) DB::table('b2b_price_tiers')->insertGetId([
                'code' => 'STANDARD',
                'name' => 'Standard',
                'priority' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('platform_customers')
            ->orderBy('id')
            ->chunkById(250, function ($platformCustomers) use ($standardTierId): void {
                foreach ($platformCustomers as $platform) {
                    DB::table('platform_customers')
                        ->where('id', $platform->id)
                        ->update([
                            'origin_channel' => $platform->origin_channel ?? 'unknown',
                            'registration_source' => $platform->registration_source ?? 'migration',
                            'registered_at' => $platform->registered_at ?? $platform->created_at ?? now(),
                            'updated_at' => now(),
                        ]);

                    DB::table('users')
                        ->where('id', $platform->user_id)
                        ->update(['is_platform_customer' => true, 'updated_at' => now()]);

                    $b2bCustomer = DB::table('b2b_customers')
                        ->where('user_id', $platform->user_id)
                        ->first();

                    if ($b2bCustomer === null) {
                        $b2bCustomer = DB::table('b2b_customers')
                            ->where('legacy_customer_id', $platform->legacy_customer_id)
                            ->first();
                    }

                    if ($b2bCustomer === null) {
                        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
                            'legacy_customer_id' => (int) $platform->legacy_customer_id,
                            'user_id' => (int) $platform->user_id,
                            'name' => (string) $platform->name,
                            'phone' => $platform->phone,
                            'email' => $platform->email,
                            'created_at' => $platform->created_at ?? now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $b2bCustomerId = (int) $b2bCustomer->id;

                        DB::table('b2b_customers')
                            ->where('id', $b2bCustomerId)
                            ->update([
                                'user_id' => $b2bCustomer->user_id ?? (int) $platform->user_id,
                                'updated_at' => now(),
                            ]);
                    }

                    $account = DB::table('b2b_accounts')
                        ->where('b2b_customer_id', $b2bCustomerId)
                        ->first();

                    if ($account === null) {
                        $account = DB::table('b2b_accounts')
                            ->where('customer_id', $platform->legacy_customer_id)
                            ->first();
                    }

                    if ($account === null) {
                        DB::table('b2b_accounts')->insert([
                            'customer_id' => (int) $platform->legacy_customer_id,
                            'b2b_customer_id' => $b2bCustomerId,
                            'price_tier_id' => (int) $standardTierId,
                            'company_name' => (string) $platform->name,
                            'status' => 'active',
                            'tax_number' => null,
                            'credit_limit' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        continue;
                    }

                    $changes = [
                        'status' => 'active',
                        'updated_at' => now(),
                    ];

                    if ($account->b2b_customer_id === null) {
                        $changes['b2b_customer_id'] = $b2bCustomerId;
                    }

                    if ($account->price_tier_id === null) {
                        $changes['price_tier_id'] = (int) $standardTierId;
                    }

                    DB::table('b2b_accounts')
                        ->where('id', $account->id)
                        ->update($changes);
                }
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_customer_wholesale_price_tier_id');
        });

        Schema::table('platform_customers', function (Blueprint $table): void {
            $table->dropIndex(['origin_channel']);
            $table->dropIndex(['registration_source']);
            $table->dropIndex(['registered_at']);
            $table->dropConstrainedForeignId('origin_store_id');
            $table->dropColumn(['origin_channel', 'registration_source', 'registered_at']);
        });
    }
};
