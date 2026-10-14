<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_calls', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('connection_profile_id')->index();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_call_id', 191)->nullable();
            $table->string('direction', 24)->index();
            $table->string('status', 32)->default('PENDING')->index();
            $table->string('event', 32)->nullable();
            $table->string('from_phone', 32)->nullable()->index();
            $table->string('to_phone', 32)->nullable()->index();
            $table->string('remote_sdp_type', 12)->nullable();
            $table->longText('remote_sdp')->nullable();
            $table->string('callback_data', 512)->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['connection_profile_id', 'provider_call_id'],
                'wa_calls_profile_provider_unique',
            );
            $table->index(
                ['conversation_id', 'status', 'last_event_at'],
                'wa_calls_conversation_status_index',
            );
        });

        $now = now();
        foreach ([
            'whatsapp.calls.view' => 'View WhatsApp voice calls',
            'whatsapp.calls.manage' => 'Accept reject and terminate WhatsApp voice calls',
            'whatsapp.calls.dial' => 'Initiate WhatsApp voice calls and permission requests',
        ] as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $permissions = DB::table('permissions')
            ->whereIn('code', [
                'whatsapp.calls.view',
                'whatsapp.calls.manage',
                'whatsapp.calls.dial',
            ])
            ->pluck('id', 'code');

        $roles = DB::table('roles')
            ->whereIn('code', [
                'CUSTOMER_SERVICE_AGENT',
                'CUSTOMER_SERVICE_SUPERVISOR',
                'CUSTOMER_SUPPORT',
                'RETAIL_CUSTOMER_SUPPORT',
            ])
            ->pluck('id', 'code');

        foreach ($roles as $roleId) {
            foreach ($permissions as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_calls');
        // Permission catalog entries stay additive and grant nothing by themselves.
    }
};
