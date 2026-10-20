<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_finance_approval_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('policy_key', 128);
            $table->char('request_hash', 64);
            $table->string('action', 64);
            $table->decimal('max_amount', 18, 3);
            $table->char('currency_code', 3)->nullable();
            $table->dateTime('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['legal_entity_id', 'policy_key'],
                'efa_limit_legal_policy_uq',
            );
            $table->index(
                ['legal_entity_id', 'action', 'currency_code', 'is_active', 'effective_from'],
                'efa_limit_action_effective_idx',
            );
            $table->index(
                ['legal_entity_id', 'role_id', 'action', 'is_active'],
                'efa_limit_role_action_idx',
            );
        });

        Schema::create('enterprise_finance_approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('enterprise_organizations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('enterprise_companies')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('enterprise_legal_entities')->restrictOnDelete();
            $table->foreignId('approval_limit_id');
            $table->foreign('approval_limit_id', 'efa_delegation_limit_fk')
                ->references('id')
                ->on('enterprise_finance_approval_limits')
                ->restrictOnDelete();
            $table->foreignId('delegator_user_id');
            $table->foreign('delegator_user_id', 'efa_delegation_delegator_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->string('delegation_key', 128);
            $table->char('request_hash', 64);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['legal_entity_id', 'delegation_key'],
                'efa_delegation_legal_key_uq',
            );
            $table->index(
                ['legal_entity_id', 'delegate_user_id', 'starts_at', 'ends_at'],
                'efa_delegation_delegate_window_idx',
            );
            $table->index(
                ['approval_limit_id', 'delegator_user_id', 'revoked_at'],
                'efa_delegation_limit_owner_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_finance_approval_delegations');
        Schema::dropIfExists('enterprise_finance_approval_limits');
    }
};
