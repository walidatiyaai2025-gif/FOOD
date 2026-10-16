<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_qr_visits', function (Blueprint $table): void {
            $table->string('type_snapshot', 32)->nullable()->after('visitor_hash');
            $table->unsignedBigInteger('promotion_id_snapshot')->nullable()->after('type_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('smart_ordering_qr_visits', function (Blueprint $table): void {
            $table->dropColumn(['type_snapshot', 'promotion_id_snapshot']);
        });
    }
};
