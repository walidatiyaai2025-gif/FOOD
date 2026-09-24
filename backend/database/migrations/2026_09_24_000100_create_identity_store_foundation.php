<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id','role_id']);
        });
        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id','user_id']);
        });
        Schema::create('store_types', function (Blueprint $table): void {
            $table->id(); $table->string('code')->unique(); $table->string('name'); $table->timestamps();
        });
        Schema::create('stores', function (Blueprint $table): void {
            $table->id(); $table->foreignId('store_type_id')->constrained(); $table->string('code')->unique(); $table->string('name'); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('user_store_roles', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('store_id')->constrained()->cascadeOnDelete(); $table->foreignId('role_id')->constrained()->cascadeOnDelete(); $table->timestamps(); $table->unique(['user_id','store_id','role_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('user_store_roles'); Schema::dropIfExists('stores'); Schema::dropIfExists('store_types'); Schema::dropIfExists('role_user'); Schema::dropIfExists('permission_role'); Schema::dropIfExists('permissions'); Schema::dropIfExists('roles');
    }
};
