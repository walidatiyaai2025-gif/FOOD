<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('field_operation_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('value_type');
            $table->json('validation_schema')->nullable();
            $table->json('default_value')->nullable();
            $table->string('failure_policy');
            $table->json('dependencies')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('field_operation_configuration_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('configuration_id')
                ->constrained('field_operation_configurations')
                ->cascadeOnDelete();
            $table->string('scope_type');
            $table->string('scope_key')->default('*');
            $table->unsignedInteger('revision_number');
            $table->string('status');
            $table->json('value')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('source_revision_id')->nullable();
            $table->foreign('source_revision_id', 'field_ops_cfg_rev_source_fk')
                ->references('id')
                ->on('field_operation_configuration_revisions')
                ->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['configuration_id', 'scope_type', 'scope_key', 'revision_number'],
                'field_ops_config_revision_unique'
            );
            $table->index(
                ['configuration_id', 'scope_type', 'scope_key', 'status'],
                'field_ops_config_effective_lookup'
            );
            $table->index(['effective_from', 'effective_until'], 'field_ops_config_effective_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_operation_configuration_revisions');
        Schema::dropIfExists('field_operation_configurations');
    }
};
