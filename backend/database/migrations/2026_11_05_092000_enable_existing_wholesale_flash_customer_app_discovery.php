<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Before Customer App visibility was configurable, every B2B Van
        // offer was discoverable in the wholesale Customer App. Preserve that
        // behavior for existing offers without changing audience restrictions.
        DB::table('flash_offers')
            ->whereIn('store_id', DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2B')
                ->select('stores.id'))
            ->where('channels', json_encode(['van'], JSON_THROW_ON_ERROR))
            ->update(['channels' => json_encode(['van', 'customer'], JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        // Intentionally non-reversing: the same channel configuration may have
        // been explicitly edited after deployment. Never overwrite live choices.
    }
};
