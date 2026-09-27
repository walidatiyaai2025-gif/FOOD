<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_inspector_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32)->index();
            $table->string('severity', 16)->index();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 12)->nullable();
            $table->string('route_name')->nullable()->index();
            $table->text('url')->nullable();
            $table->text('message');
            $table->string('exception_class')->nullable();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_inspector_events');
    }
};
