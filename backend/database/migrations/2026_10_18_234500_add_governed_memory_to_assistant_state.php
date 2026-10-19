<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversation_state', function (Blueprint $table): void {
            $table->json('safe_preferences')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_conversation_state', function (Blueprint $table): void {
            $table->dropColumn('safe_preferences');
        });
    }
};
