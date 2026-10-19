<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_finance_source_postings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 96);
            $table->unsignedSmallInteger('event_version');
            $table->string('source_domain', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_reference', 128)->nullable();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->foreignId('journal_id')->unique()->constrained('enterprise_finance_journals')->restrictOnDelete();
            $table->dateTime('occurred_at');
            $table->dateTime('posted_at');
            $table->timestamps();

            $table->unique(
                ['source_domain', 'source_type', 'source_id', 'event_type', 'event_version'],
                'efsp_source_event_version_uq',
            );
            $table->index(
                ['organization_id', 'company_id', 'legal_entity_id', 'store_id', 'occurred_at', 'id'],
                'efsp_scope_occurred_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_source_postings');
    }
};
