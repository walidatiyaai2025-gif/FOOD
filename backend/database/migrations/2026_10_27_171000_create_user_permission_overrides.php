<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('has_permission_overrides')->default(false);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('scope_key', 64)->default('global');
            $table->foreignId('store_id')->nullable()->constrained('stores')->cascadeOnDelete();
            $table->string('effect', 8);
            $table->timestamps();

            $table->unique(
                ['user_id', 'permission_id', 'scope_key'],
                'user_permission_overrides_unique',
            );
            $table->index(
                ['user_id', 'scope_key', 'effect'],
                'user_permission_overrides_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('has_permission_overrides');
        });
    }
};
