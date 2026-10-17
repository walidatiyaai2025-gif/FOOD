<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_saas_api_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('request_count')->default(0);
            $table->unsignedBigInteger('rate_limit_violations')->default(0);
            $table->timestamp('last_rate_limit_at')->nullable();
        });

        Schema::table('restaurant_saas_integration_events', function (Blueprint $table): void {
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
        });

        Schema::table('restaurant_saas_webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->timestamp('last_replayed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_saas_webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['replay_count', 'last_replayed_at']);
        });

        Schema::table('restaurant_saas_integration_events', function (Blueprint $table): void {
            $table->dropColumn(['replay_count', 'last_replayed_at']);
        });

        Schema::table('restaurant_saas_api_clients', function (Blueprint $table): void {
            $table->dropColumn(['request_count', 'rate_limit_violations', 'last_rate_limit_at']);
        });
    }
};
