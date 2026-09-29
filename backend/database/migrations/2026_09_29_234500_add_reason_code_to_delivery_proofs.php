<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->string('reason_code', 80)->nullable()->after('otp_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->dropIndex(['reason_code']);
            $table->dropColumn('reason_code');
        });
    }
};
