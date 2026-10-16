<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_location_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('lifecycle_state', 24)->default('DRAFT');
            $table->string('timezone', 64)->nullable();
            $table->char('currency', 3)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('business_name', 160)->nullable();
            $table->string('legal_name', 160)->nullable();
            $table->string('tax_registration_number', 80)->nullable();
            $table->timestamps();

            $table->index(['lifecycle_state', 'currency'], 'restaurant_location_profile_state_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_location_profiles');
    }
};
