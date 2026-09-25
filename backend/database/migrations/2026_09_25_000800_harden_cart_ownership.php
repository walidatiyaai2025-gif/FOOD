<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->unique('guest_token', 'carts_guest_token_unique');
            $table->unique(
                ['store_id', 'customer_id', 'channel'],
                'carts_customer_store_channel_unique',
            );
            $table->index(['customer_id', 'channel'], 'carts_customer_channel_index');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropIndex('carts_customer_channel_index');
            $table->dropUnique('carts_customer_store_channel_unique');
            $table->dropUnique('carts_guest_token_unique');
        });
    }
};
