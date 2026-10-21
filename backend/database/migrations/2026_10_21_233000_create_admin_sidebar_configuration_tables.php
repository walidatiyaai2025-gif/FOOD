<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_sidebar_groups', function (Blueprint $table): void {
            $table->string('group_key', 80)->primary();
            $table->string('label_ar', 160)->nullable();
            $table->string('label_en', 160)->nullable();
            $table->string('icon', 16)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('admin_sidebar_items', function (Blueprint $table): void {
            $table->string('item_key', 120)->primary();
            $table->string('group_key', 80);
            $table->string('parent_key', 120)->nullable();
            $table->string('label_ar', 160)->nullable();
            $table->string('label_en', 160)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['group_key', 'sort_order']);
            $table->index('parent_key');
        });

        Schema::create('admin_sidebar_role_visibility', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('item_key', 120);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->primary(['role_id', 'item_key']);
            $table->index(['item_key', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sidebar_role_visibility');
        Schema::dropIfExists('admin_sidebar_items');
        Schema::dropIfExists('admin_sidebar_groups');
    }
};
