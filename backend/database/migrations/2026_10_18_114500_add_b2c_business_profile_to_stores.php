<?php

use App\Services\B2cBusinessProfileBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'business_profile')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->string('business_profile', 32)
                    ->nullable()
                    ->after('store_type_id')
                    ->index();
            });
        }

        if (! Schema::hasTable('store_business_profile_backfills')) {
            Schema::create('store_business_profile_backfills', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->unique();
                $table->string('business_profile', 32)->index();
                $table->string('classification_reason', 80);
                $table->timestamp('classified_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('b2c_business_profile_backfill_audit')) {
            Schema::create('b2c_business_profile_backfill_audit', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete()->unique();
                $table->string('previous_profile', 32)->nullable();
                $table->string('assigned_profile', 32);
                $table->string('source', 80);
                $table->timestamp('converted_at');
                $table->timestamps();
            });
        }

        app(B2cBusinessProfileBackfillService::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('b2c_business_profile_backfill_audit');
        Schema::dropIfExists('store_business_profile_backfills');

        if (Schema::hasColumn('stores', 'business_profile')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropIndex(['business_profile']);
                $table->dropColumn('business_profile');
            });
        }
    }
};
