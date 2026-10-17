<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_e164', 24)->nullable()->unique()->after('email_verified_at');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_e164');
        });

        Schema::create('admin_whatsapp_otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone_e164', 24)->index();
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('resend_available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('send_failed_at')->nullable();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->string('request_ip_hash', 64)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at', 'expires_at'], 'admin_wa_otp_user_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_whatsapp_otp_challenges');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone_e164']);
            $table->dropColumn(['phone_e164', 'phone_verified_at']);
        });
    }
};
