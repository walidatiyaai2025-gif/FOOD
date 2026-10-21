<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_service_cases', function (Blueprint $table): void {
            $table->string('contact_phone', 50)->nullable()->after('description');
            $table->index(['contact_phone', 'status'], 'cs_cases_contact_phone_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_service_cases', function (Blueprint $table): void {
            $table->dropIndex('cs_cases_contact_phone_status_idx');
            $table->dropColumn('contact_phone');
        });
    }
};
