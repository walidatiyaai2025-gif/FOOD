<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_treasury_cheques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('enterprise_bank_accounts')->restrictOnDelete();
            $table->string('instrument_number', 80);
            $table->string('direction', 16);
            $table->string('treasury_reference_type', 16);
            $table->unsignedBigInteger('treasury_reference_id');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->decimal('amount', 18, 3);
            $table->string('currency_code', 3);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('payee_or_drawer', 190)->nullable();
            $table->string('status', 16);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_transition_at')->nullable();
            $table->foreignId('last_transition_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'etcq_store_idem_uq');
            $table->unique(
                ['treasury_reference_type', 'treasury_reference_id'],
                'etcq_treasury_reference_uq',
            );
            $table->index(
                [
                    'organization_id',
                    'company_id',
                    'legal_entity_id',
                    'store_id',
                    'status',
                    'due_date',
                    'id',
                ],
                'etcq_scope_status_due_idx',
            );
            $table->index(
                ['store_id', 'bank_account_id', 'due_date', 'status', 'id'],
                'etcq_bank_due_status_idx',
            );
            $table->index(
                ['store_id', 'direction', 'instrument_number', 'id'],
                'etcq_store_direction_number_idx',
            );
        });

        Schema::create('enterprise_treasury_cheque_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_id')
                ->constrained('enterprise_treasury_cheques')
                ->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('workflow_request_id')->nullable();
            $table->foreign('workflow_request_id', 'etcqt_workflow_request_fk')
                ->references('id')
                ->on('enterprise_workflow_requests')
                ->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['store_id', 'idempotency_key'], 'etcqt_store_idem_uq');
            $table->index(
                ['cheque_id', 'to_status', 'occurred_at', 'id'],
                'etcqt_cheque_status_time_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_treasury_cheque_transitions');
        Schema::dropIfExists('enterprise_treasury_cheques');
    }
};
