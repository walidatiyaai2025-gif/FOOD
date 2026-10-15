<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_module_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->string('module_code', 100);
            $table->string('scope_key', 160);
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('capabilities')->nullable();
            $table->timestamps();

            $table->unique(
                ['module_code', 'scope_key'],
                'enterprise_module_entitlements_module_scope_unique',
            );
            $table->index(
                ['scope_type', 'scope_id', 'is_enabled'],
                'enterprise_module_entitlements_scope_enabled_idx',
            );
            $table->index(
                ['module_code', 'is_enabled'],
                'enterprise_module_entitlements_module_enabled_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_module_entitlements');
    }
};
