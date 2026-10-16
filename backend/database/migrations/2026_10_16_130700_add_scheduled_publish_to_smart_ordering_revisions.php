<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_ordering_revisions', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['status', 'scheduled_at'], 'smart_ordering_revisions_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::table('smart_ordering_revisions', function (Blueprint $table): void {
            $table->dropIndex('smart_ordering_revisions_schedule_idx');
            $table->dropForeign(['scheduled_by']);
            $table->dropColumn(['scheduled_at', 'scheduled_by']);
        });
    }
};
