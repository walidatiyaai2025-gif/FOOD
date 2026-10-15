<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_onboarding_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('format', 12);
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('status', 24)->default('REVIEW_REQUIRED');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('formula_rows')->default(0);
            $table->json('headers');
            $table->json('sample')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status', 'created_at'], 'restaurant_onboarding_import_store_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_onboarding_imports');
    }
};
