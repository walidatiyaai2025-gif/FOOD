<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_channel_operational_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('lifecycle_state', 24)->default('ACTIVE');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
            $table->index(['lifecycle_state', 'changed_at'], 'store_channel_lifecycle_idx');
        });

        $now = now();
        $rows = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->get(['stores.id', 'stores.is_active'])
            ->map(fn (object $store): array => [
                'store_id' => (int) $store->id,
                'lifecycle_state' => (bool) $store->is_active ? 'ACTIVE' : 'SUSPENDED',
                'reason' => 'Backfilled from canonical stores.is_active state.',
                'changed_by' => null,
                'changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($rows !== []) {
            DB::table('store_channel_operational_profiles')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_channel_operational_profiles');
    }
};
