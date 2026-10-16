<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_treasury_integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('source_reference', 64);
            $table->uuid('event_id')->unique();
            $table->string('event_type', 96);
            $table->unsignedSmallInteger('event_version');
            $table->char('currency_code', 3);
            $table->json('consumer_hints');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'event_type', 'event_version'],
                'ette_source_event_version_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'occurred_at', 'id'],
                'ette_scope_occurred_idx',
            );
            $table->index(
                ['event_type', 'event_version', 'occurred_at', 'id'],
                'ette_contract_occurred_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_treasury_integration_events');
    }
};
