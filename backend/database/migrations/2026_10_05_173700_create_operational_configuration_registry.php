<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120);
            $table->string('scope_type', 32)->default('platform');
            $table->string('scope_key', 120)->default('global');
            $table->unsignedInteger('revision');
            $table->string('status', 20)->default('draft');
            $table->string('value_type', 24);
            $table->json('value');
            $table->json('schema')->nullable();
            $table->json('dependencies')->nullable();
            $table->string('failure_mode', 24)->default('degrade');
            $table->boolean('is_emergency')->default(false);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['key', 'scope_type', 'scope_key', 'revision'],
                'operational_config_scope_revision_unique',
            );
            $table->index(
                ['key', 'scope_type', 'scope_key', 'status', 'effective_from'],
                'operational_config_effective_index',
            );
        });

        Schema::create('operational_configuration_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_configuration_id')
                ->constrained('operational_configurations')
                ->cascadeOnDelete();
            $table->string('action', 32);
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(
                ['operational_configuration_id', 'created_at'],
                'operational_config_audit_timeline_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_configuration_audits');
        Schema::dropIfExists('operational_configurations');
    }
};
