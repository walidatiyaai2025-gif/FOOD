<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('driver_assignment_id')->constrained()->nullOnDelete();
            $table->string('from_status', 80)->nullable()->after('proof_type');
            $table->string('to_status', 80)->nullable()->after('from_status');
            $table->index(['driver_assignment_id', 'captured_at'], 'delivery_proofs_assignment_captured_index');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('dedupe_key', 128)->nullable()->after('type');
            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_user_dedupe_unique');
            $table->dropColumn('dedupe_key');
        });

        Schema::table('delivery_proofs', function (Blueprint $table): void {
            $table->dropIndex('delivery_proofs_assignment_captured_index');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['from_status', 'to_status']);
        });
    }
};
