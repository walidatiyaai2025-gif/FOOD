<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurant_location_profiles', function (Blueprint $table): void {
            $table->timestamp('canceled_at')->nullable()->after('tax_registration_number');
            $table->timestamp('offboarded_at')->nullable()->after('canceled_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_location_profiles', function (Blueprint $table): void {
            $table->dropColumn(['canceled_at', 'offboarded_at']);
        });
    }
};
