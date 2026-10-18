<?php

use App\Services\B2cBusinessProfileBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('business_profile', 32)->nullable()->after('store_type_id')->index();
        });

        Schema::create('b2c_business_profile_backfill_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete()->unique();
            $table->string('previous_profile', 32)->nullable();
            $table->string('assigned_profile', 32);
            $table->string('source', 80);
            $table->timestamp('converted_at');
            $table->timestamps();
        });

        app(B2cBusinessProfileBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('b2c_business_profile_backfill_audit');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropIndex(['business_profile']);
            $table->dropColumn('business_profile');
        });
    }
};
