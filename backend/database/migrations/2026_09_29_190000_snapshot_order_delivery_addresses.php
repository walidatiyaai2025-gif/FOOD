<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('delivery_address_snapshot')->nullable()->after('address_id');
            $table->decimal('delivery_latitude', 10, 7)->nullable()->after('delivery_address_snapshot');
            $table->decimal('delivery_longitude', 10, 7)->nullable()->after('delivery_latitude');
            $table->index(
                ['delivery_latitude', 'delivery_longitude'],
                'orders_delivery_coordinates_index',
            );
        });

        DB::table('orders')
            ->whereNotNull('address_id')
            ->whereNull('delivery_address_snapshot')
            ->orderBy('id')
            ->chunkById(250, function ($orders): void {
                foreach ($orders as $order) {
                    $address = DB::table('addresses')
                        ->where('id', $order->address_id)
                        ->first();

                    if ($address === null) {
                        continue;
                    }

                    $snapshot = $this->snapshot($address);

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'delivery_address_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                            'delivery_latitude' => $snapshot['latitude'],
                            'delivery_longitude' => $snapshot['longitude'],
                            'updated_at' => $order->updated_at ?? now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_delivery_coordinates_index');
            $table->dropColumn([
                'delivery_address_snapshot',
                'delivery_latitude',
                'delivery_longitude',
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(object $address): array
    {
        return [
            'version' => 1,
            'address_id' => (int) $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name ?? null,
            'delivery_phone' => $address->delivery_phone ?? null,
            'line1' => (string) $address->line1,
            'line2' => $address->line2,
            'city' => (string) $address->city,
            'area' => $address->area,
            'country_code' => (string) $address->country_code,
            'country' => $address->country ?? null,
            'governorate' => $address->governorate ?? null,
            'block' => $address->block ?? null,
            'street' => $address->street ?? $address->line1,
            'avenue' => $address->avenue ?? null,
            'building' => $address->building ?? null,
            'floor' => $address->floor ?? null,
            'apartment' => $address->apartment ?? null,
            'landmark' => $address->landmark ?? null,
            'delivery_notes' => $address->delivery_notes ?? null,
            'latitude' => $address->latitude === null ? null : (float) $address->latitude,
            'longitude' => $address->longitude === null ? null : (float) $address->longitude,
            'location_accuracy_meters' => $address->location_accuracy_meters === null
                ? null
                : (float) $address->location_accuracy_meters,
            'location_source' => (string) ($address->location_source ?? 'manual'),
        ];
    }
};
