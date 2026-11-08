<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_owner_registry', function (Blueprint $table): void {
            // Exactly one protected, internally provisioned identity.
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestamp('provisioned_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_owner_registry');
    }
};
