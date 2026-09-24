<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); $table->string('driver_type'); $table->boolean('is_available')->default(false); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('driver_assignments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('driver_id')->constrained()->cascadeOnDelete(); $table->foreignId('order_id')->constrained()->cascadeOnDelete(); $table->string('assignment_type'); $table->string('status')->default('assigned'); $table->timestamp('assigned_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->timestamps(); $table->index(['driver_id','status']);
        });
        Schema::create('delivery_proofs', function (Blueprint $table): void {
            $table->id(); $table->foreignId('driver_assignment_id')->constrained()->cascadeOnDelete(); $table->string('proof_type'); $table->string('file_path')->nullable(); $table->string('otp_hash')->nullable(); $table->text('note')->nullable(); $table->timestamp('captured_at')->nullable(); $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); $table->string('channel'); $table->string('type'); $table->string('title'); $table->text('body'); $table->json('data')->nullable(); $table->timestamp('read_at')->nullable(); $table->timestamps();
        });
        Schema::create('app_versions', function (Blueprint $table): void {
            $table->id(); $table->string('app'); $table->string('platform'); $table->string('latest_version'); $table->string('minimum_supported_version'); $table->boolean('force_update')->default(false); $table->string('store_url')->nullable(); $table->text('release_notes')->nullable(); $table->timestamps(); $table->unique(['app','platform']);
        });
        Schema::create('system_versions', function (Blueprint $table): void {
            $table->id(); $table->string('version')->unique(); $table->timestamp('installed_at'); $table->string('package_hash')->nullable(); $table->timestamps();
        });
        Schema::create('update_history', function (Blueprint $table): void {
            $table->id(); $table->string('from_version')->nullable(); $table->string('to_version'); $table->string('status'); $table->string('package_hash')->nullable(); $table->text('release_notes')->nullable(); $table->text('failure_reason')->nullable(); $table->timestamp('started_at')->nullable(); $table->timestamp('finished_at')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('event'); $table->string('auditable_type')->nullable(); $table->unsignedBigInteger('auditable_id')->nullable(); $table->json('before')->nullable(); $table->json('after')->nullable(); $table->string('ip_address',45)->nullable(); $table->text('user_agent')->nullable(); $table->timestamps(); $table->index(['auditable_type','auditable_id']);
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete(); $table->string('key'); $table->json('value')->nullable(); $table->boolean('is_secret')->default(false); $table->timestamps(); $table->unique(['store_id','key']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('settings'); Schema::dropIfExists('audit_logs'); Schema::dropIfExists('update_history'); Schema::dropIfExists('system_versions'); Schema::dropIfExists('app_versions'); Schema::dropIfExists('notifications'); Schema::dropIfExists('delivery_proofs'); Schema::dropIfExists('driver_assignments'); Schema::dropIfExists('drivers');
    }
};
