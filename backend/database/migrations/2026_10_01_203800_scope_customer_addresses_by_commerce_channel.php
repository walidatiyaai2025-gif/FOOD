<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('commerce_channel', 8)
                ->default('b2c')
                ->after('platform_customer_id');

            $table->index(
                ['platform_customer_id', 'commerce_channel', 'is_default'],
                'addresses_platform_channel_default_index',
            );
            $table->index(
                ['commerce_channel', 'b2b_customer_id', 'b2c_customer_id'],
                'addresses_channel_domain_index',
            );
        });

        DB::table('addresses')
            ->whereNotNull('b2b_customer_id')
            ->update(['commerce_channel' => 'b2b']);

        DB::table('addresses')
            ->whereNotNull('b2c_customer_id')
            ->update(['commerce_channel' => 'b2c']);

        DB::table('addresses')
            ->whereNotNull('platform_customer_id')
            ->orderBy('id')
            ->chunkById(250, function ($addresses): void {
                foreach ($addresses as $address) {
                    $origin = DB::table('platform_customers')
                        ->where('id', $address->platform_customer_id)
                        ->value('origin_channel');
                    $channel = strtolower(trim((string) $origin));

                    if (! in_array($channel, ['b2b', 'b2c'], true)) {
                        continue;
                    }

                    DB::table('addresses')
                        ->where('id', $address->id)
                        ->update([
                            'commerce_channel' => $channel,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropIndex('addresses_platform_channel_default_index');
            $table->dropIndex('addresses_channel_domain_index');
            $table->dropColumn('commerce_channel');
        });
    }
};
