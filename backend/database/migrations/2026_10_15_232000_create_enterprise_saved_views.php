<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('surface_key', 120);
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 96);
            $table->string('name', 120);
            $table->string('visibility', 16)->default('private');
            $table->string('search', 120)->nullable();
            $table->json('filters')->nullable();
            $table->string('sort_field', 80)->nullable();
            $table->string('sort_direction', 4)->default('asc');
            $table->json('columns')->nullable();
            $table->unsignedSmallInteger('per_page')->default(25);
            $table->timestamps();

            $table->unique(
                ['user_id', 'surface_key', 'scope_key', 'name'],
                'enterprise_saved_views_owner_surface_name_unique',
            );
            $table->index(
                ['surface_key', 'scope_key', 'visibility'],
                'enterprise_saved_views_surface_scope_visibility_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_saved_views');
    }
};
