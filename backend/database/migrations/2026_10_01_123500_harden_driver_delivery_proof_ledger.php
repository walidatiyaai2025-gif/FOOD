<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->foreignId('order_id')
                ->nullable()
                ->after('driver_assignment_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('idempotency_key', 128)
                ->nullable()
                ->after('order_id');
            $table->string('request_fingerprint', 64)
                ->nullable()
                ->after('idempotency_key');
            $table->unique(
                ['driver_assignment_id', 'idempotency_key'],
                'delivery_proofs_assignment_idempotency_unique',
            );
        });

        DB::table('delivery_proofs')
            ->select(['id', 'driver_assignment_id'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $orderId = DB::table('driver_assignments')
                        ->where('id', $row->driver_assignment_id)
                        ->value('order_id');

                    if ($orderId !== null) {
                        DB::table('delivery_proofs')
                            ->where('id', $row->id)
                            ->update(['order_id' => $orderId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->dropUnique('delivery_proofs_assignment_idempotency_unique');
            $table->dropColumn(['request_fingerprint', 'idempotency_key']);
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
