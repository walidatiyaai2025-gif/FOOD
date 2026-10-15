<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_user_data_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['user_id', 'scope_type', 'scope_id'],
                'enterprise_user_data_scopes_user_scope_unique',
            );
            $table->index(
                ['scope_type', 'scope_id'],
                'enterprise_user_data_scopes_scope_idx',
            );
            $table->index(
                ['user_id', 'starts_at', 'ends_at'],
                'enterprise_user_data_scopes_active_idx',
            );
        });

        Schema::create('enterprise_user_approval_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('workflow_key', 120);
            $table->char('currency', 3);
            $table->decimal('amount', 18, 4);
            $table->string('scope_type', 40)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 180)->default('global');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['user_id', 'workflow_key', 'currency', 'scope_key'],
                'enterprise_user_approval_limits_rule_unique',
            );
            $table->index(
                ['user_id', 'workflow_key', 'currency'],
                'enterprise_user_approval_limits_lookup_idx',
            );
            $table->index(
                ['scope_type', 'scope_id'],
                'enterprise_user_approval_limits_scope_idx',
            );
        });

        Schema::create('enterprise_user_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->string('permission_code', 160);
            $table->string('scope_type', 40)->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 180)->default('global');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['delegate_id', 'permission_code', 'starts_at', 'ends_at'],
                'enterprise_user_delegations_authorization_idx',
            );
            $table->index(
                ['delegator_id', 'revoked_at'],
                'enterprise_user_delegations_delegator_idx',
            );
            $table->index(
                ['scope_type', 'scope_id'],
                'enterprise_user_delegations_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_user_delegations');
        Schema::dropIfExists('enterprise_user_approval_limits');
        Schema::dropIfExists('enterprise_user_data_scopes');
    }
};
