<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_profitability_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('minimum_margin_basis_points');
            $table->date('effective_from');
            $table->date('effective_until');
            // No ACTIVE status is possible from this draft/review interface.
            // Operational activation needs trusted P110 cost and P129 approval.
            $table->string('status', 32);
            $table->uuid('request_id');
            $table->char('content_sha256', 64);
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'product_id', 'revision'], 'profitability_revision_scope_uq');
            $table->unique(['store_id', 'request_id'], 'profitability_request_scope_uq');
            $table->index(['store_id', 'product_id', 'status', 'effective_from'], 'profitability_status_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('commercial_profitability_rule_revisions')
            && DB::table('commercial_profitability_rule_revisions')->exists()) {
            throw new RuntimeException(
                'Refusing to drop commercial_profitability_rule_revisions because immutable proposal/review history exists. Archive it explicitly before rollback.',
            );
        }

        Schema::dropIfExists('commercial_profitability_rule_revisions');
    }
};
