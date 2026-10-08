<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sms_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider',64)->default('advansys_bulk_sms');
            $table->boolean('enabled')->default(false);
            $table->string('environment',32)->default('production');
            $table->string('api_base_url')->default('https://hub.advansystelecom.com');
            $table->string('endpoint_path')->default('/generalapiv12/api/bulkSMS/ForwardSMS');
            $table->text('api_token_encrypted')->nullable();
            $table->string('default_sender_name')->nullable();
            $table->string('default_country_code',8)->default('20');
            $table->string('operator_resolution_mode',32)->default('automatic');
            $table->unsignedSmallInteger('request_timeout')->default(10);
            $table->unsignedTinyInteger('retry_count')->default(2);
            $table->unsignedSmallInteger('retry_backoff_seconds')->default(2);
            $table->boolean('delivery_logging')->default(true);
            $table->boolean('otp_sender_enabled')->default(false);
            $table->boolean('notification_sender_enabled')->default(false);
            $table->timestamps();
            $table->unique(['provider','environment']);
        });

        Schema::create('sms_message_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('phone_hash',64);
            $table->string('recipient_masked',32);
            $table->string('country',8)->nullable();
            $table->unsignedTinyInteger('operator_id')->nullable();
            $table->string('sender',64)->nullable();
            $table->string('purpose',64);
            $table->string('locale',8)->default('ar');
            $table->string('provider',64);
            $table->string('status',32)->default('pending');
            $table->string('provider_response_code',32)->nullable();
            $table->string('error_code',64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('is_test')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['purpose','status']);
            $table->index(['phone_hash','created_at']);
        });

        $now=now();
        DB::table('permissions')->upsert([
            ['code'=>'sms_settings.manage','name'=>'Manage central SMS provider settings','created_at'=>$now,'updated_at'=>$now],
            ['code'=>'sms_settings.test','name'=>'Send controlled SMS gateway tests','created_at'=>$now,'updated_at'=>$now],
        ],['code'],['name','updated_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_message_logs');
        Schema::dropIfExists('sms_provider_settings');
        $ids=DB::table('permissions')->whereIn('code',['sms_settings.manage','sms_settings.test'])->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id',$ids)->delete();
            DB::table('permissions')->whereIn('id',$ids)->delete();
        }
    }
};
