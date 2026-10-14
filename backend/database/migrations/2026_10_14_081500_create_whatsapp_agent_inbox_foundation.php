<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_app_agent_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('OFFLINE')->index();
            $table->unsignedInteger('capacity')->default(20);
            $table->boolean('accepts_new')->default(true);
            $table->foreignId('delegated_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delegated_until')->nullable();
            $table->timestamps();
        });

        Schema::create('whats_app_customer_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_customer_id')->unique()->constrained('platform_customers')->cascadeOnDelete();
            $table->foreignId('primary_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('backup_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 16)->nullable()->index();
            $table->string('source', 32)->default('manual');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create('whats_app_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 24)->default('whatsapp')->index();
            $table->string('status', 32)->default('WAITING_FOR_AGENT')->index();
            $table->foreignId('primary_agent_id')->nullable()->constrained('users')->nullOnDelete()->index();
            $table->unsignedTinyInteger('priority')->default(0)->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('waiting_since')->nullable()->index();
            $table->timestamp('first_inbound_at')->nullable();
            $table->timestamp('first_agent_response_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->index(['store_id', 'status', 'waiting_since'], 'wa_conversation_queue_index');
        });

        Schema::create('whats_app_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whats_app_conversations')->cascadeOnDelete();
            $table->string('direction', 12)->index();
            $table->string('actor_type', 24)->default('customer');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('attachment_metadata')->nullable();
            $table->string('provider_message_ref')->nullable()->index();
            $table->string('delivery_status', 32)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('whats_app_internal_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whats_app_conversations')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('whats_app_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('whats_app_conversation_tag', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->constrained('whats_app_conversations')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('whats_app_tags')->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->primary(['conversation_id', 'tag_id']);
        });

        Schema::create('whats_app_assignment_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('priority')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 24)->nullable()->index();
            $table->string('strategy', 32)->default('round_robin');
            $table->json('conditions')->nullable();
            $table->json('configuration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whats_app_assignment_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('channel', 24)->nullable();
            $table->foreignId('old_primary_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_primary_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('old_backup_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('new_backup_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32);
            $table->string('reason', 500)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        $now = now();
        foreach ([
            'whatsapp.inbox.view' => 'View WhatsApp shared inbox',
            'whatsapp.conversations.view' => 'View WhatsApp conversations in authorized scope',
            'whatsapp.conversations.reply' => 'Create provider-neutral outbound reply records',
            'whatsapp.conversations.assign' => 'Assign unassigned WhatsApp conversations',
            'whatsapp.conversations.reassign' => 'Reassign WhatsApp conversations',
            'whatsapp.internal_notes.manage' => 'Create WhatsApp internal notes',
            'whatsapp.customer_service.supervise' => 'Supervise WhatsApp customer service',
            'whatsapp.agent_status.manage' => 'Manage WhatsApp agent availability and capacity',
            'whatsapp.assignment_rules.manage' => 'Manage WhatsApp assignment rules',
        ] as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        foreach ([
            ['code' => 'CUSTOMER_SERVICE_AGENT', 'name' => 'Customer Service Agent'],
            ['code' => 'CUSTOMER_SERVICE_SUPERVISOR', 'name' => 'Customer Service Supervisor'],
        ] as $role) {
            DB::table('roles')->updateOrInsert(
                ['code' => $role['code']],
                [
                    'name' => $role['name'],
                    'scope' => 'global',
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $permissions = DB::table('permissions')->whereIn('code', [
            'whatsapp.inbox.view',
            'whatsapp.conversations.view',
            'whatsapp.conversations.reply',
            'whatsapp.conversations.assign',
            'whatsapp.conversations.reassign',
            'whatsapp.internal_notes.manage',
            'whatsapp.customer_service.supervise',
            'whatsapp.agent_status.manage',
            'whatsapp.assignment_rules.manage',
            'stores.view',
            'customers.view',
            'orders.view',
        ])->pluck('id', 'code');
        $roles = DB::table('roles')
            ->whereIn('code', [
                'CUSTOMER_SERVICE_AGENT',
                'CUSTOMER_SERVICE_SUPERVISOR',
                'CUSTOMER_SUPPORT',
                'RETAIL_CUSTOMER_SUPPORT',
            ])
            ->pluck('id', 'code');

        $agentCodes = [
            'whatsapp.inbox.view',
            'whatsapp.conversations.view',
            'whatsapp.conversations.reply',
            'whatsapp.conversations.assign',
            'whatsapp.internal_notes.manage',
        ];
        $serviceAgentCodes = array_merge($agentCodes, [
            'stores.view',
            'customers.view',
            'orders.view',
        ]);
        $supervisorCodes = array_keys($permissions->all());

        foreach ([
            'CUSTOMER_SERVICE_AGENT' => $serviceAgentCodes,
            'CUSTOMER_SERVICE_SUPERVISOR' => $supervisorCodes,
            'CUSTOMER_SUPPORT' => $agentCodes,
            'RETAIL_CUSTOMER_SUPPORT' => $agentCodes,
        ] as $roleCode => $codes) {
            $roleId = $roles[$roleCode] ?? null;
            if ($roleId === null) {
                continue;
            }

            foreach ($codes as $code) {
                $permissionId = $permissions[$code] ?? null;
                if ($permissionId !== null) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        foreach ([
            'vip' => 'VIP',
            'complaint' => 'Complaint',
            'payment' => 'Payment',
            'order-issue' => 'Order Issue',
            'wholesale' => 'Wholesale',
            'hot-lead' => 'Hot Lead',
        ] as $code => $name) {
            DB::table('whats_app_tags')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_app_assignment_history');
        Schema::dropIfExists('whats_app_assignment_rules');
        Schema::dropIfExists('whats_app_conversation_tag');
        Schema::dropIfExists('whats_app_tags');
        Schema::dropIfExists('whats_app_internal_notes');
        Schema::dropIfExists('whats_app_messages');
        Schema::dropIfExists('whats_app_conversations');
        Schema::dropIfExists('whats_app_customer_assignments');
        Schema::dropIfExists('whats_app_agent_profiles');
    }
};
