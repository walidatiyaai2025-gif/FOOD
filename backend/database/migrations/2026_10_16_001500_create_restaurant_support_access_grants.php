<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('restaurant_support_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('case_reference', 120);
            $table->string('reason_code', 40);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['store_id', 'revoked_at', 'expires_at'],
                'restaurant_support_access_active_idx',
            );
            $table->index('case_reference', 'restaurant_support_access_case_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_support_access_grants');
    }
};
