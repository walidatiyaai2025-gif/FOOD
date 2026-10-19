<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_role_data_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 96);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'scope_key'], 'enterprise_role_scope_unique');
            $table->index(['scope_type', 'scope_id', 'is_active'], 'enterprise_role_scope_lookup_idx');
        });

        Schema::create('enterprise_approval_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission_code', 160);
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 96);
            $table->char('currency', 3);
            $table->decimal('max_amount', 18, 3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['role_id', 'permission_code', 'scope_key', 'currency'],
                'enterprise_approval_limit_unique',
            );
            $table->index(
                ['permission_code', 'scope_type', 'scope_id', 'is_active'],
                'enterprise_approval_limit_lookup_idx',
            );
        });

        Schema::create('enterprise_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->string('permission_code', 160);
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 96);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['delegate_id', 'permission_code', 'starts_at', 'ends_at'],
                'enterprise_delegation_active_idx',
            );
            $table->index(['scope_type', 'scope_id'], 'enterprise_delegation_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_delegations');
        Schema::dropIfExists('enterprise_approval_limits');
        Schema::dropIfExists('enterprise_role_data_scopes');
    }
};
