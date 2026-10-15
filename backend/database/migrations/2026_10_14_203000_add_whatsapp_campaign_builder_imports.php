<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_campaign_contact_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable()->index();
            $table->string('source_type', 16)->index();
            $table->string('original_filename', 255)->nullable();
            $table->string('stored_path', 500)->nullable();
            $table->string('status', 24)->default('preview')->index();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->json('summary')->nullable();
            $table->timestamps();
        });

        Schema::table('whats_app_campaigns', function (Blueprint $table): void {
            $table->string('audience_source', 24)->default('existing')->after('segment')->index();
            $table->foreignId('import_batch_id')
                ->nullable()
                ->after('audience_source')
                ->constrained('whats_app_campaign_contact_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('whats_app_campaigns', 'import_batch_id')) {
            Schema::table('whats_app_campaigns', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('import_batch_id');
                $table->dropColumn('audience_source');
            });
        }

        Schema::dropIfExists('whats_app_campaign_contact_imports');
    }
};
