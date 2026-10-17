<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Domain\WhatsApp\ConversationService;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WhatsAppAgentInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_can_exist_without_agent_assignment(): void
    {
        $store = $this->store('B2B', 'WA-NO-AGENT');
        $customer = $this->customer($store, 'b2b', 'No Agent');

        $this->assertDatabaseHas('platform_customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('whats_app_customer_assignments', [
            'platform_customer_id' => $customer->id,
        ]);
    }

    public function test_manual_assignment_is_durable_and_audited(): void
    {
        $store = $this->store('B2B', 'WA-MANUAL');
        $customer = $this->customer($store, 'b2b', 'Manual Customer');
        $conversation = $this->conversation($customer->id, $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Supervisor');
        $agent = $this->onlineAgent('Agent One');
        $backup = $this->onlineAgent('Backup Agent');

        app(ConversationService::class)->assign(
            $conversation,
            $supervisor,
            $agent->id,
            0,
            'manual acceptance',
            $backup->id,
        );

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $agent->id,
            'status' => 'OPEN',
            'lock_version' => 1,
        ]);
        $this->assertDatabaseHas('whats_app_customer_assignments', [
            'platform_customer_id' => $customer->id,
            'primary_agent_id' => $agent->id,
            'backup_agent_id' => $backup->id,
            'source' => 'manual',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('whats_app_assignment_history', [
            'conversation_id' => $conversation,
            'new_primary_agent_id' => $agent->id,
            'actor_user_id' => $supervisor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.customer_assigned']);
    }

    public function test_round_robin_is_deterministic(): void
    {
        $store = $this->store('B2B', 'WA-RR');
        $first = $this->onlineAgent('Round Robin A');
        $second = $this->onlineAgent('Round Robin B');

        $ids = [$first->id, $second->id];
        sort($ids);

        $customerA = $this->customer($store, 'b2b', 'RR Customer A');
        $customerB = $this->customer($store, 'b2b', 'RR Customer B');

        $conversationA = app(ConversationService::class)
            ->findOrCreateForInbound($customerA->id, $store, 'whatsapp');
        $conversationB = app(ConversationService::class)
            ->findOrCreateForInbound($customerB->id, $store, 'whatsapp');

        $this->assertSame(
            $ids[0],
            (int) DB::table('whats_app_conversations')->where('id', $conversationA)->value('primary_agent_id'),
        );
        $this->assertSame(
            $ids[1],
            (int) DB::table('whats_app_conversations')->where('id', $conversationB)->value('primary_agent_id'),
        );
    }

    public function test_least_loaded_respects_capacity_and_offline_agents_are_skipped(): void
    {
        $store = $this->store('B2B', 'WA-LOAD');
        $full = $this->onlineAgent('Full Agent', 1);
        $available = $this->onlineAgent('Available Agent', 5);
        $offline = $this->onlineAgent('Offline Agent', 5);

        app(AgentAssignmentEngine::class)->setStatus($offline, 'OFFLINE', 5, true);

        $customer = $this->customer($store, 'b2b', 'Existing Load');
        $this->conversation($customer->id, $store, $full->id);

        $eligible = app(AgentAssignmentEngine::class)->eligibleAgentIds($store, 'whatsapp');
        $this->assertNotContains($full->id, $eligible);
        $this->assertNotContains($offline->id, $eligible);
        $this->assertContains($available->id, $eligible);

        $this->assertSame(
            $available->id,
            app(AgentAssignmentEngine::class)->choose($store, 'whatsapp', 'least_loaded'),
        );
    }

    public function test_no_eligible_agent_falls_back_to_unassigned_queue(): void
    {
        $store = $this->store('B2B', 'WA-UNASSIGNED');
        $customer = $this->customer($store, 'b2b', 'Queue Customer');

        $conversation = app(ConversationService::class)
            ->findOrCreateForInbound($customer->id, $store, 'whatsapp');

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => null,
            'status' => 'WAITING_FOR_AGENT',
        ]);
    }

    public function test_store_scope_is_enforced_and_unauthorized_agent_fails_closed(): void
    {
        $retailA = $this->store('B2C', 'WA-RETAIL-A');
        $retailB = $this->store('B2C', 'WA-RETAIL-B');

        $agent = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $retailA, 'Retail Agent');
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', 5, true);

        $this->assertContains(
            $agent->id,
            app(AgentAssignmentEngine::class)->eligibleAgentIds($retailA, 'whatsapp'),
        );
        $this->assertNotContains(
            $agent->id,
            app(AgentAssignmentEngine::class)->eligibleAgentIds($retailB, 'whatsapp'),
        );

        $customer = $this->customer($retailB, 'b2c', 'Protected Customer');
        $conversation = $this->conversation($customer->id, $retailB);
        $this->enableInbox();

        $this->actingAs($agent)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertForbidden();
    }

    public function test_supervisor_reassignment_preserves_history_and_stale_write_fails_safely(): void
    {
        $store = $this->store('B2B', 'WA-REASSIGN');
        $customer = $this->customer($store, 'b2b', 'Reassign Customer');
        $conversation = $this->conversation($customer->id, $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Reassign Supervisor');
        $first = $this->onlineAgent('Primary A');
        $second = $this->onlineAgent('Primary B');

        $service = app(ConversationService::class);
        $service->assign($conversation, $supervisor, $first->id, 0, 'initial');
        $this->message($conversation, 'inbound', 'History remains');

        try {
            $service->assign($conversation, $supervisor, $second->id, 0, 'stale');
            $this->fail('Stale assignment must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('whats_app_conversations', [
                'id' => $conversation,
                'primary_agent_id' => $first->id,
                'lock_version' => 1,
            ]);
        }

        $service->assign($conversation, $supervisor, $second->id, 1, 'shift change');

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $second->id,
            'lock_version' => 2,
        ]);
        $this->assertSame(
            2,
            DB::table('whats_app_assignment_history')->where('conversation_id', $conversation)->count(),
        );
        $this->assertSame(
            1,
            DB::table('whats_app_messages')->where('conversation_id', $conversation)->count(),
        );
        $this->assertDatabaseHas('whats_app_assignment_history', [
            'conversation_id' => $conversation,
            'old_primary_agent_id' => $first->id,
            'new_primary_agent_id' => $second->id,
            'reason' => 'shift change',
        ]);
    }

    public function test_internal_note_can_never_become_customer_visible_outbound_content(): void
    {
        $store = $this->store('B2B', 'WA-NOTES');
        $customer = $this->customer($store, 'b2b', 'Notes Customer');
        $conversation = $this->conversation($customer->id, $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Notes Supervisor');
        $agent = $this->onlineAgent('Notes Agent');

        $service = app(ConversationService::class);
        $service->assign($conversation, $supervisor, $agent->id, 0);
        $service->addInternalNote($conversation, $supervisor, 'Never transmit this');
        $service->appendOutboundPending($conversation, $supervisor, 'Customer-visible reply');

        $this->assertDatabaseHas('whats_app_internal_notes', [
            'conversation_id' => $conversation,
            'body' => 'Never transmit this',
        ]);
        $this->assertDatabaseMissing('whats_app_messages', [
            'conversation_id' => $conversation,
            'body' => 'Never transmit this',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'conversation_id' => $conversation,
            'direction' => 'outbound',
            'body' => 'Customer-visible reply',
            'delivery_status' => 'pending_transport',
            'provider_message_ref' => null,
        ]);
    }

    public function test_resolve_and_reopen_preserve_sla_events_and_audit(): void
    {
        $store = $this->store('B2B', 'WA-LIFECYCLE');
        $customer = $this->customer($store, 'b2b', 'Lifecycle Customer');
        $conversation = $this->conversation($customer->id, $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Lifecycle Supervisor');
        $agent = $this->onlineAgent('Lifecycle Agent');

        $service = app(ConversationService::class);
        $service->assign($conversation, $supervisor, $agent->id, 0);
        $service->resolve($conversation, $supervisor);

        $this->assertNotNull(
            DB::table('whats_app_conversations')->where('id', $conversation)->value('resolved_at'),
        );

        $service->resolve($conversation, $supervisor, true);

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'status' => 'OPEN',
            'resolved_at' => null,
        ]);
        $this->assertNotNull(
            DB::table('whats_app_conversations')->where('id', $conversation)->value('reopened_at'),
        );
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.conversation_resolved']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.conversation_reopened']);
    }

    public function test_customer_360_reads_authoritative_food_orders_invoices_and_payments(): void
    {
        $store = $this->store('B2B', 'WA-C360');
        $customer = $this->customer($store, 'b2b', 'Customer 360');
        $conversation = $this->conversation($customer->id, $store);

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer->legacy_customer_id,
            'order_number' => 'WA-ORDER-001',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 12.5,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = (int) DB::table('invoices')->insertGetId([
            'order_id' => $order,
            'store_id' => $store,
            'customer_id' => $customer->legacy_customer_id,
            'invoice_number' => 'WA-INV-001',
            'channel' => 'b2b',
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 12.5,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'order_id' => $order,
            'invoice_id' => $invoice,
            'provider' => 'cash_on_delivery',
            'status' => 'paid',
            'amount' => 12.5,
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->globalUser('SUPER_ADMIN', 'Customer 360 Admin');
        $this->enableInbox();

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertOk()
            ->assertSee('WA-ORDER-001')
            ->assertSee('WA-INV-001')
            ->assertSee('Cash on delivery');

        $this->assertSame(1, DB::table('orders')->where('order_number', 'WA-ORDER-001')->count());
        $this->assertSame(1, DB::table('invoices')->where('invoice_number', 'WA-INV-001')->count());
    }

    public function test_inbox_route_is_fail_closed_when_capability_is_disabled(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'Disabled Admin');

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertNotFound();

        $this->assertSame('DISABLED', DB::table('whats_app_module_states')->value('lifecycle'));
    }

    public function test_inbox_renders_arabic_rtl_and_english_ltr(): void
    {
        $this->enableInbox();
        $admin = $this->globalUser('SUPER_ADMIN', 'Locale Admin');

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('صندوق خدمة العملاء');

        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Shared Customer Service Inbox');
    }

    public function test_inbox_conversation_rail_preserves_filters_selection_and_latest_preview(): void
    {
        $this->enableInbox();
        $store = $this->store('B2B', 'WA-LIST-GOLDEN');
        $selectedCustomer = $this->customer($store, 'b2b', 'Golden Queue Customer');
        $otherCustomer = $this->customer($store, 'b2b', 'Other Queue Customer');
        $selectedConversation = $this->conversation($selectedCustomer->id, $store);
        $otherConversation = $this->conversation($otherCustomer->id, $store);

        $this->message($selectedConversation, 'inbound', 'Latest customer preview for the golden rail');
        $this->message($otherConversation, 'inbound', 'Other conversation preview');
        DB::table('whats_app_conversations')
            ->where('id', $selectedConversation)
            ->update(['unread_count' => 3]);

        $admin = $this->globalUser('SUPER_ADMIN', 'Conversation Rail Admin');
        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', [
                'q' => 'Golden Queue',
                'status' => 'WAITING_FOR_AGENT',
                'assignment' => 'unassigned',
                'store_id' => $store,
                'channel' => 'whatsapp',
                'conversation' => $selectedConversation,
            ]))
            ->assertOk()
            ->assertSee('wa-conversation-pane', false)
            ->assertSee('wa-conversation-filters', false)
            ->assertSee('class="wa-row active"', false)
            ->assertSee('Golden Queue Customer')
            ->assertSee('Latest customer preview for the golden rail')
            ->assertSee('بانتظار الرد')
            ->assertSee('مزيد من الفلاتر')
            ->assertSee('name="assignment"', false)
            ->assertSee('name="store_id"', false)
            ->assertSee('name="channel"', false)
            ->assertDontSee('Other Queue Customer')
            ->assertDontSee('Other conversation preview');

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', [
                'q' => 'Other Queue',
                'assignment' => 'unassigned',
                'store_id' => $store,
                'channel' => 'whatsapp',
                'conversation' => $otherConversation,
            ]))
            ->assertOk()
            ->assertSee('Other Queue Customer')
            ->assertSee('Other conversation preview')
            ->assertDontSee('Golden Queue Customer');
    }

    public function test_inbox_auto_scrolls_selected_conversation_to_latest_message(): void
    {
        $this->enableInbox();
        $store = $this->store('B2B', 'WA-AUTOSCROLL');
        $customer = $this->customer($store, 'b2b', 'Autoscroll Customer');
        $conversation = $this->conversation($customer->id, $store);
        $this->message($conversation, 'inbound', 'Newest customer message');

        $admin = $this->globalUser('SUPER_ADMIN', 'Autoscroll Admin');

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertOk()
            ->assertSee('id="wa-conversation-timeline"', false)
            ->assertSee('timeline.scrollTop = timeline.scrollHeight;', false)
            ->assertSee('new MutationObserver', false)
            ->assertSee('Newest customer message');
    }

    public function test_inbox_can_send_render_and_securely_open_an_attachment(): void
    {
        Storage::fake('local');

        $store = $this->store('B2B', 'WA-ATTACHMENT');
        $customer = $this->customer($store, 'b2b', 'Attachment Customer');
        $conversation = $this->conversation($customer->id, $store);
        $admin = $this->globalUser('SUPER_ADMIN', 'Attachment Admin');
        $this->enableInbox();

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-inbox.reply', ['conversation' => $conversation]), [
                'attachment' => UploadedFile::fake()->create('invoice.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]));

        $message = DB::table('whats_app_messages')
            ->where('conversation_id', $conversation)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('document', $message->content_type);
        $metadata = json_decode((string) $message->attachment_metadata, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('document', $metadata['media_type']);
        $this->assertSame('invoice.pdf', $metadata['filename']);
        Storage::disk('local')->assertExists($metadata['local_path']);

        $attachmentUrl = route('admin.whatsapp-inbox.attachments.show', [
            'conversation' => $conversation,
            'message' => $message->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertOk()
            ->assertSee('invoice.pdf')
            ->assertSee($attachmentUrl, false);

        $response = $this->actingAs($admin)->get($attachmentUrl)->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    public function test_inbox_renders_receive_and_make_call_controls_when_calling_is_enabled(): void
    {
        $store = $this->store('B2B', 'WA-CALL-UI');
        $customer = $this->customer($store, 'b2b', 'Voice UI Customer');
        $conversation = $this->conversation($customer->id, $store);
        $admin = $this->globalUser('SUPER_ADMIN', 'Voice UI Admin');
        $this->enableInbox(true);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertOk()
            ->assertSee('اتصال واتساب')
            ->assertSee('طلب إذن اتصال')
            ->assertSee('مكالمة واتساب واردة')
            ->assertSee('wa-call-tools', false)
            ->assertSee('wa-call-answer', false);
    }

    public function test_agent_status_capacity_change_is_audited_through_operational_route(): void
    {
        $this->store('B2B', 'WA-STATUS');
        $agent = $this->onlineAgent('Status Agent', 10);
        $this->enableInbox();

        $this->actingAs($agent)
            ->patch(route('admin.whatsapp-inbox.status'), [
                'status' => 'BUSY',
                'capacity' => 7,
                'accepts_new' => 0,
            ])
            ->assertRedirect(route('admin.whatsapp-inbox.index'));

        $this->assertDatabaseHas('whats_app_agent_profiles', [
            'user_id' => $agent->id,
            'status' => 'BUSY',
            'capacity' => 7,
            'accepts_new' => 0,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'whatsapp.agent_status_changed',
            'user_id' => $agent->id,
        ]);
    }

    public function test_assignment_rules_can_target_store_channel_and_fall_back_safely(): void
    {
        $store = $this->store('B2B', 'WA-RULE');
        $first = $this->onlineAgent('Rule Agent A');
        $preferred = $this->onlineAgent('Rule Agent B');

        DB::table('whats_app_assignment_rules')->insert([
            'name' => 'Preferred store queue',
            'priority' => 1,
            'is_active' => true,
            'store_id' => $store,
            'channel' => 'whatsapp',
            'strategy' => 'round_robin',
            'conditions' => json_encode(['foundation' => 'future-segmentation']),
            'configuration' => json_encode(['agent_ids' => [$preferred->id]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            $preferred->id,
            app(AgentAssignmentEngine::class)->choose($store, 'whatsapp', 'rule_based'),
        );
        $this->assertNotSame($first->id, $preferred->id);
    }

    public function test_migration_is_additive_and_rollback_is_deterministic(): void
    {
        $tables = [
            'whats_app_agent_profiles',
            'whats_app_customer_assignments',
            'whats_app_conversations',
            'whats_app_messages',
            'whats_app_internal_notes',
            'whats_app_tags',
            'whats_app_assignment_rules',
            'whats_app_assignment_history',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' must exist after migration.');
        }

        $migration = require database_path(
            'migrations/2026_10_14_081500_create_whatsapp_agent_inbox_foundation.php',
        );

        $migration->down();

        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must roll back.');
        }
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('payments'));

        $migration->up();

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' must restore deterministically.');
        }
    }

    public function test_final_golden_workspace_keeps_three_worker_panes_and_certification_geometry(): void
    {
        $view = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-wa-golden-workspace', $view);
        $this->assertStringContainsString('data-golden-reference="docs/golden/customer-service-inbox/customer-service-inbox-golden.webp"', $view);
        $this->assertStringContainsString('data-wa-pane="conversation-list"', $view);
        $this->assertStringContainsString('data-wa-pane="active-conversation"', $view);
        $this->assertStringContainsString('data-wa-pane="customer-context"', $view);
        $this->assertStringContainsString('grid-template-columns:448px minmax(0,1fr) 379px', $view);
        $this->assertStringContainsString('grid-template-areas:"context thread rail"', $view);
        $this->assertStringContainsString('html[dir=ltr] .wa-golden-workspace{grid-template-areas:"rail thread context"}', $view);

        $golden = base_path('../docs/golden/customer-service-inbox/customer-service-inbox-golden.webp');
        $this->assertFileExists($golden);

        $size = getimagesize($golden);
        $this->assertIsArray($size);
        $this->assertSame(1504, $size[0]);
        $this->assertSame(851, $size[1]);
    }

    public function test_customer_360_context_pane_has_golden_section_order_and_locale_parity(): void
    {
        $view = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        $sections = [
            'customer-details',
            'classifications-tags',
            'purchase-summary',
            'recent-orders',
            'invoices-payments',
            'activity-history',
        ];

        $previousPosition = -1;
        foreach ($sections as $section) {
            $needle = 'data-wa-context-section="'.$section.'"';
            $position = strpos($view, $needle);

            $this->assertNotFalse($position, $needle.' must exist in the Customer 360 pane.');
            $this->assertGreaterThan($previousPosition, $position, $needle.' must preserve golden section order.');
            $previousPosition = $position;
        }

        foreach ([
            'data-wa-pane="customer-context"',
            'data-wa-context-stack',
            'class="wa-context-stats"',
            'class="wa-context-smart"',
            'class="wa-activity-list"',
            '$customer360[\'orders\']',
            '$customer360[\'invoices\']',
            '$customer360[\'payments\']',
            '$smartOrderingContext[\'orders\']',
            '$timeline->take(-4)',
        ] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }

        $en = require lang_path('en/whatsapp.php');
        $ar = require lang_path('ar/whatsapp.php');

        foreach ([
            'customer_details',
            'classifications_tags',
            'no_tags',
            'purchase_summary',
            'invoices_payments',
            'activity_history',
            'no_activity',
        ] as $key) {
            $this->assertArrayHasKey($key, $en['inbox']);
            $this->assertArrayHasKey($key, $ar['inbox']);
            $this->assertNotSame('', trim((string) $en['inbox'][$key]));
            $this->assertNotSame('', trim((string) $ar['inbox'][$key]));
        }
    }

    public function test_inbox_center_thread_composer_has_golden_structure_and_locale_parity(): void
    {
        $view = file_get_contents(resource_path('views/admin/whatsapp-inbox.blade.php'));

        foreach ([
            'data-wa-pane="active-conversation"',
            'class="wa-thread-head"',
            'class="wa-thread-meta"',
            'id="wa-conversation-timeline"',
            'class="wa-compose-wrap"',
            'data-wa-quick-toggle',
            'data-wa-note-toggle',
            'data-wa-reply',
            "route('admin.whatsapp-inbox.assign'",
            "route('admin.whatsapp-inbox.tag'",
            "route('admin.whatsapp-inbox.transition'",
            "route('admin.whatsapp-inbox.reply'",
            "route('admin.whatsapp-inbox.note'",
            "route('admin.whatsapp-calls.feed')",
        ] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }

        $en = require lang_path('en/whatsapp.php');
        $ar = require lang_path('ar/whatsapp.php');

        $this->assertSame(
            array_keys($en['inbox']['thread_ui']),
            array_keys($ar['inbox']['thread_ui']),
        );
        $this->assertSame([
            'conversation_classification',
            'responsible',
            'priority',
            'priority_normal',
            'priority_high',
            'tags',
            'more',
            'manage_assignment',
            'today',
            'quick_replies',
            'attach_order',
            'no_quick_replies',
            'write_message',
        ], array_keys($en['inbox']['thread_ui']));
    }

    public function test_inbox_center_thread_composer_renders_real_arabic_thread_actions(): void
    {
        $this->enableInbox();
        $store = $this->store('B2B', 'WA-CENTER-GOLDEN');
        $customer = $this->customer($store, 'b2b', 'Golden Center Customer');
        $conversation = $this->conversation($customer->id, $store);
        $this->message($conversation, 'inbound', 'Golden center customer message');

        $admin = $this->globalUser('SUPER_ADMIN', 'Golden Center Admin');
        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
            ->assertOk()
            ->assertSee('data-wa-pane="active-conversation"', false)
            ->assertSee('تصنيف المحادثة')
            ->assertSee('المسؤول')
            ->assertSee('الأولوية')
            ->assertSee('الوسوم')
            ->assertSee('ردود سريعة')
            ->assertSee('إرفاق طلب')
            ->assertSee('اكتب رسالتك هنا...')
            ->assertSee('data-wa-note-toggle', false)
            ->assertSee('data-wa-reply', false)
            ->assertSee('Golden center customer message');
    }

    private function enableInbox(bool $calls = false): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => $calls
                ? ['inbound' => true, 'agent_inbox' => true, 'outbound' => true, 'calls' => true]
                : ['agent_inbox' => true],
            'test_numbers' => $calls ? ['+96550000000'] : [],
        ])->save();
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $name): PlatformCustomer
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformCustomer::query()->create([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    private function globalUser(string $roleCode, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeUser(string $roleCode, int $storeId, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function onlineAgent(string $name, int $capacity = 20): User
    {
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', $name);
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', $capacity, true);

        return $agent;
    }

    private function conversation(
        int $platformCustomerId,
        int $storeId,
        ?int $agentId = null,
    ): int {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $platformCustomerId,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => $agentId === null ? 'WAITING_FOR_AGENT' : 'OPEN',
            'primary_agent_id' => $agentId,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => $agentId === null ? now() : null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function message(int $conversationId, string $direction, string $body): int
    {
        return (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'direction' => $direction,
            'actor_type' => $direction === 'inbound' ? 'customer' : 'agent',
            'body' => $body,
            'delivery_status' => $direction === 'inbound' ? 'received' : 'pending_transport',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
