<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enterprise_purchase_requests', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->after('notes');
            $table->char('idempotency_payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(
                ['organization_id', 'company_id', 'store_id', 'created_by', 'idempotency_key'],
                'enterprise_purchase_requests_idempotency_scope_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('enterprise_purchase_requests', function (Blueprint $table): void {
            $table->dropUnique('enterprise_purchase_requests_idempotency_scope_unique');
            $table->dropColumn(['idempotency_key', 'idempotency_payload_hash']);
        });
    }
};
