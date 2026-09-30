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
            $table->foreignId('platform_customer_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('platform_customers')
                ->cascadeOnDelete();
            $table->string('recipient_name')->nullable()->after('label');
            $table->string('delivery_phone', 50)->nullable()->after('recipient_name');
            $table->string('country', 120)->nullable()->after('country_code');
            $table->string('governorate', 120)->nullable()->after('country');
            $table->string('block', 120)->nullable()->after('area');
            $table->string('street', 255)->nullable()->after('block');
            $table->string('avenue', 120)->nullable()->after('street');
            $table->string('building', 120)->nullable()->after('avenue');
            $table->string('floor', 120)->nullable()->after('building');
            $table->string('apartment', 120)->nullable()->after('floor');
            $table->string('landmark', 255)->nullable()->after('apartment');
            $table->text('delivery_notes')->nullable()->after('landmark');
            $table->decimal('location_accuracy_meters', 10, 2)->nullable()->after('longitude');
            $table->string('location_source', 32)->default('manual')->after('location_accuracy_meters');
            $table->softDeletes();

            $table->index(
                ['platform_customer_id', 'is_default'],
                'addresses_platform_default_index',
            );
            $table->index(
                ['platform_customer_id', 'deleted_at'],
                'addresses_platform_deleted_index',
            );
        });

        DB::table('addresses')
            ->whereNull('platform_customer_id')
            ->orderBy('id')
            ->chunkById(250, function ($addresses): void {
                foreach ($addresses as $address) {
                    $platformCustomerId = DB::table('platform_customers')
                        ->where('legacy_customer_id', $address->customer_id)
                        ->value('id');

                    if ($platformCustomerId === null) {
                        continue;
                    }

                    DB::table('addresses')
                        ->where('id', $address->id)
                        ->update([
                            'platform_customer_id' => (int) $platformCustomerId,
                            'street' => $address->line1,
                            'location_source' => 'manual',
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropIndex('addresses_platform_default_index');
            $table->dropIndex('addresses_platform_deleted_index');
            $table->dropConstrainedForeignId('platform_customer_id');
            $table->dropColumn([
                'recipient_name',
                'delivery_phone',
                'country',
                'governorate',
                'block',
                'street',
                'avenue',
                'building',
                'floor',
                'apartment',
                'landmark',
                'delivery_notes',
                'location_accuracy_meters',
                'location_source',
                'deleted_at',
            ]);
        });
    }
};
