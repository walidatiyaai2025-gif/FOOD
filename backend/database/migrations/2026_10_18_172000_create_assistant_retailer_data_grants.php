<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_retailer_data_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('wholesale_store_id')->constrained('stores')->restrictOnDelete();
            $table->string('basis', 32);
            $table->string('purpose', 64)->default('assistant_analytics');
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['retailer_store_id', 'wholesale_store_id', 'purpose'],
                'assistant_retailer_grant_scope_unique',
            );
            $table->index(
                ['wholesale_store_id', 'purpose', 'status', 'starts_at'],
                'assistant_retailer_grant_wholesale_idx',
            );
            $table->index(
                ['retailer_store_id', 'purpose', 'status'],
                'assistant_retailer_grant_retail_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_retailer_data_grants');
    }
};
