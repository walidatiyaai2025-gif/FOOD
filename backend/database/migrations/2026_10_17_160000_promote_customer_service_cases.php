<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('whats_app_conversations')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('delivery_reference', 120)->nullable();
            $table->string('type', 64)->default('other')->index();
            $table->string('status', 24)->default('open')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->string('queue', 64)->nullable()->index();
            $table->string('subject', 190)->nullable();
            $table->text('description')->nullable();
            $table->json('attachment_metadata')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sla_target_at')->nullable()->index();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('service_recovery_code', 64)->nullable()->index();
            $table->text('service_recovery_note')->nullable();
            $table->unsignedTinyInteger('csat_score')->nullable();
            $table->unsignedTinyInteger('nps_score')->nullable();
            $table->timestamp('feedback_consent_at')->nullable();
            $table->string('feedback_context', 190)->nullable();
            $table->string('source', 32)->default('agent')->index();
            $table->timestamps();

            $table->index(['store_id', 'status', 'sla_target_at', 'id'], 'cs_cases_store_status_sla_idx');
            $table->index(['platform_customer_id', 'status', 'id'], 'cs_cases_customer_status_idx');
            $table->index(['assigned_agent_id', 'status', 'id'], 'cs_cases_agent_status_idx');
        });

        Schema::create('customer_service_case_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('customer_service_cases')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->string('storage_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_internal')->default(true);
            $table->timestamps();

            $table->index(['case_id', 'id'], 'cs_case_notes_case_idx');
        });

        if (Schema::hasTable('whats_app_chatbot_cases')) {
            DB::table('whats_app_chatbot_cases')
                ->orderBy('id')
                ->chunkById(250, function ($rows): void {
                    foreach ($rows as $row) {
                        $storeId = DB::table('whats_app_conversations')
                            ->where('id', $row->conversation_id)
                            ->value('store_id');

                        if ($storeId === null && $row->order_id !== null) {
                            $storeId = DB::table('orders')->where('id', $row->order_id)->value('store_id');
                        }
                        if ($storeId === null && $row->platform_customer_id !== null) {
                            $storeId = DB::table('platform_customers')
                                ->where('id', $row->platform_customer_id)
                                ->value('origin_store_id');
                        }

                        $createdAt = $row->created_at === null
                            ? CarbonImmutable::now()
                            : CarbonImmutable::parse((string) $row->created_at);
                        $priority = strtolower((string) ($row->priority ?: 'normal'));
                        $slaHours = match ($priority) {
                            'urgent' => 2,
                            'high' => 8,
                            'low' => 48,
                            default => 24,
                        };

                        DB::table('customer_service_cases')->insert([
                            'id' => (int) $row->id,
                            'public_id' => $row->public_id,
                            'store_id' => $storeId,
                            'platform_customer_id' => $row->platform_customer_id,
                            'conversation_id' => $row->conversation_id,
                            'order_id' => $row->order_id,
                            'invoice_id' => null,
                            'delivery_reference' => null,
                            'type' => $row->type ?: 'other',
                            'status' => $row->status ?: 'open',
                            'priority' => $priority,
                            'queue' => 'whatsapp',
                            'subject' => null,
                            'description' => $row->description,
                            'attachment_metadata' => $row->attachment_metadata,
                            'assigned_agent_id' => $row->assigned_agent_id,
                            'created_by' => $row->assigned_agent_id,
                            'updated_by' => $row->assigned_agent_id,
                            'sla_target_at' => $createdAt->addHours($slaHours),
                            'escalated_at' => null,
                            'resolved_at' => $row->resolved_at,
                            'service_recovery_code' => null,
                            'service_recovery_note' => null,
                            'csat_score' => null,
                            'nps_score' => null,
                            'feedback_consent_at' => null,
                            'feedback_context' => null,
                            'source' => 'chatbot',
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ]);
                    }
                });

            Schema::drop('whats_app_chatbot_cases');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('whats_app_chatbot_cases')) {
            Schema::create('whats_app_chatbot_cases', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('conversation_id')->constrained('whats_app_conversations')->cascadeOnDelete();
                $table->foreignId('platform_customer_id')->nullable()->constrained('platform_customers')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('type', 64)->index();
                $table->string('status', 24)->default('open')->index();
                $table->string('priority', 16)->default('normal')->index();
                $table->text('description')->nullable();
                $table->json('attachment_metadata')->nullable();
                $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['platform_customer_id', 'status'], 'wa_chatbot_case_customer_status_idx');
            });

            DB::table('customer_service_cases')
                ->whereNotNull('conversation_id')
                ->orderBy('id')
                ->chunkById(250, function ($rows): void {
                    foreach ($rows as $row) {
                        DB::table('whats_app_chatbot_cases')->insert([
                            'id' => (int) $row->id,
                            'public_id' => $row->public_id,
                            'conversation_id' => $row->conversation_id,
                            'platform_customer_id' => $row->platform_customer_id,
                            'order_id' => $row->order_id,
                            'type' => $row->type,
                            'status' => $row->status,
                            'priority' => $row->priority,
                            'description' => $row->description,
                            'attachment_metadata' => $row->attachment_metadata,
                            'assigned_agent_id' => $row->assigned_agent_id,
                            'resolved_at' => $row->resolved_at,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ]);
                    }
                });
        }

        Schema::dropIfExists('customer_service_case_notes');
        Schema::dropIfExists('customer_service_cases');
    }
};
