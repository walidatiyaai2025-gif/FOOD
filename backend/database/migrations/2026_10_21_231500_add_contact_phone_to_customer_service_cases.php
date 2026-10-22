<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('customer_service_cases')) {
            return;
        }

        if (! Schema::hasColumn('customer_service_cases', 'contact_phone')) {
            Schema::table('customer_service_cases', function (Blueprint $table): void {
                $table->string('contact_phone', 50)->nullable()->after('description');
            });
        }

        $indexes = array_column(Schema::getIndexes('customer_service_cases'), 'name');
        if (! in_array('cs_cases_contact_phone_status_idx', $indexes, true)) {
            Schema::table('customer_service_cases', function (Blueprint $table): void {
                $table->index(['contact_phone', 'status'], 'cs_cases_contact_phone_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_service_cases')) {
            return;
        }

        $indexes = array_column(Schema::getIndexes('customer_service_cases'), 'name');
        if (in_array('cs_cases_contact_phone_status_idx', $indexes, true)) {
            Schema::table('customer_service_cases', function (Blueprint $table): void {
                $table->dropIndex('cs_cases_contact_phone_status_idx');
            });
        }

        if (Schema::hasColumn('customer_service_cases', 'contact_phone')) {
            Schema::table('customer_service_cases', function (Blueprint $table): void {
                $table->dropColumn('contact_phone');
            });
        }
    }
};
