<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enterprise_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('category', 64)->index();
            $table->unsignedInteger('manifest_version');
            $table->string('state', 32)->default('DISABLED');
            $table->json('manifest');
            $table->text('last_error')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('state_changed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['state', 'category'], 'enterprise_modules_state_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_modules');
    }
};
