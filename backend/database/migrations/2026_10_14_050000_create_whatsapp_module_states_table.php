<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_module_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('lifecycle', 24)->default('DISABLED');
            $table->boolean('emergency_stop')->default(true);
            $table->json('capabilities')->nullable();
            $table->json('test_numbers')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
        DB::table('whats_app_module_states')->insert([
            'id' => 1, 'lifecycle' => 'DISABLED', 'emergency_stop' => true,
            'capabilities' => '{}', 'test_numbers' => '[]', 'revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['view', 'settings', 'lifecycle', 'emergency', 'activate'] as $action) {
            DB::table('permissions')->insertOrIgnore([
                'code' => 'whatsapp.'.$action, 'name' => 'WhatsApp '.$action,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_module_states');
        // Keep audit history and additive permission catalog entries on rollback.
        // Neither grants access without server-side code and explicit assignment.
    }
};
