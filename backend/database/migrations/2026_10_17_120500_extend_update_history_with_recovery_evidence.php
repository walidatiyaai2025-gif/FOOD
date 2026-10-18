<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('update_history', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('environment', 64)->nullable()->after('status');
            $table->string('operation_reason', 80)->nullable()->after('environment');
            $table->string('backup_status', 32)->nullable()->after('operation_reason');
            $table->text('file_backup_reference')->nullable()->after('backup_status');
            $table->text('database_backup_reference')->nullable()->after('file_backup_reference');
            $table->string('failed_stage', 80)->nullable()->after('database_backup_reference');
            $table->string('rollback_status', 32)->nullable()->after('failed_stage');
            $table->boolean('maintenance_mode_remained')->default(false)->after('rollback_status');
            $table->timestamp('health_verified_at')->nullable()->after('maintenance_mode_remained');
            $table->json('execution_evidence')->nullable()->after('health_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('update_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn([
                'environment',
                'operation_reason',
                'backup_status',
                'file_backup_reference',
                'database_backup_reference',
                'failed_stage',
                'rollback_status',
                'maintenance_mode_remained',
                'health_verified_at',
                'execution_evidence',
            ]);
        });
    }
};
