<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_release_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->string('app', 32);
            $table->string('version', 64);
            $table->string('filename');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('expected_bytes')->nullable();
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->string('local_path')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->unique(['app', 'version']);
            $table->index(['version', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_release_artifacts');
    }
};
