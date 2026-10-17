<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ChatbotAutomationService;
use App\Domain\WhatsApp\ChatbotIntentService;
use App\Domain\WhatsApp\ChatbotRuntimePolicy;
use App\Domain\WhatsApp\MetaCloudWhatsAppProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_all_six_admin_surfaces_and_eight_customer_previews_render_in_foodex_shell(): void
    {
        $admin = $this->superAdmin();

        foreach (['control', 'features', 'flows', 'ai', 'segments', 'analytics'] as $section) {
            $this->actingAs($admin)
                ->get(route('admin.whatsapp-chatbot.index', ['section' => $section]))
                ->assertOk()
                ->assertSee('foodex-admin-layout', false);
        }

        foreach (['home', 'new-order', 'reorder', 'products', 'cart', 'orders', 'finance', 'support'] as $screen) {
            $this->actingAs($admin)
                ->get(route('admin.whatsapp-chatbot.preview', ['screen' => $screen]))
                ->assertOk()
                ->assertSee('foodex-admin-layout', false);
        }
    }

    public function test_chatbot_navigation_remains_available_for_management_while_runtime_is_disabled(): void
    {
        $admin = $this->superAdmin();

        $this->assertFalse(app(ChatbotRuntimePolicy::class)->enabled());

        $marketing = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'marketing');

        $this->assertIsArray($marketing);
        $this->assertContains('whatsapp_chatbot', array_column($marketing['children'], 'key'));
    }

    public function test_runtime_controls_and_feature_flags_are_persisted_with_revision_guard(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put(route('admin.whatsapp-chatbot.runtime.update'), [
                'scope' => 'runtime',
                'revision' => 0,
                'enabled' => 1,
                'lifecycle' => 'INTERNAL_TEST',
                'pilot_segment_codes' => [],
                'emergency_stop' => 0,
                'mode' => 'hybrid',
                'default_locale' => 'ar',
                'working_from' => '08:00',
                'working_to' => '22:00',
                'after_hours_handoff' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('whats_app_chatbot_settings', [
            'enabled' => 1,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => 0,
            'mode' => 'hybrid',
            'revision' => 1,
            'updated_by' => $admin->id,
        ]);

        $feature = DB::table('whats_app_chatbot_features')->where('code', 'finance')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('admin.whatsapp-chatbot.features.update', ['feature' => $feature->id]), [
                'enabled' => 1,
                'visible' => 1,
                'ai_allowed' => 0,
                'sort_order' => 25,
                'segment_codes' => [],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('whats_app_chatbot_features', [
            'id' => $feature->id,
            'enabled' => 1,
            'visible' => 1,
            'sort_order' => 25,
        ]);
    }

    public function test_flow_builder_versions_publish_and_rollback_without_raw_json_input(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-chatbot.flows.store'), [
                'code' => 'welcome-order',
                'name_ar' => 'مسار الطلب',
                'name_en' => 'Order flow',
                'message_ar' => 'ابدأ طلبك من هنا',
                'message_en' => 'Start your order here',
                'primary_action' => 'new_order',
            ])
            ->assertRedirect();

        $flow = DB::table('whats_app_chatbot_flows')->where('code', 'welcome_order')->firstOrFail();
        $this->assertSame(1, (int) $flow->version);

        $this->actingAs($admin)
            ->patch(route('admin.whatsapp-chatbot.flows.update', ['flow' => $flow->id]), [
                'name_ar' => 'مسار الطلب المحدث',
                'name_en' => 'Updated order flow',
                'message_ar' => 'اختر منتجاتك',
                'message_en' => 'Choose your products',
                'primary_action' => 'new_order',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-chatbot.flows.publish', ['flow' => $flow->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('whats_app_chatbot_flows', [
            'id' => $flow->id,
            'status' => 'published',
            'version' => 2,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-chatbot.flows.rollback', ['flow' => $flow->id]))
            ->assertRedirect();

        $rolled = DB::table('whats_app_chatbot_flows')->where('id', $flow->id)->firstOrFail();
        $definition = json_decode((string) $rolled->definition, true);

        $this->assertSame('draft', $rolled->status);
        $this->assertSame(3, (int) $rolled->version);
        $this->assertSame('ابدأ طلبك من هنا', $definition['nodes'][0]['content']['ar']);
        $this->assertDatabaseHas('whats_app_chatbot_flow_versions', [
            'flow_id' => $flow->id,
            'version' => 3,
            'status' => 'draft',
        ]);
    }

    public function test_chatbot_runtime_fails_closed_until_bot_and_whatsapp_automation_are_enabled(): void
    {
        $phone = '+96550000111';
        $conversationId = $this->conversation(null, null, $phone);

        $this->assertFalse(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => [$phone],
        ])->save();

        $this->assertTrue(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));
    }

    public function test_pilot_runtime_only_handles_customers_in_selected_segments(): void
    {
        $storeId = $this->store('B2B', 'WA-BOT-PILOT');
        $customerId = $this->customer($storeId, 'b2b', '+96550000444');
        $conversationId = $this->conversation($customerId, $storeId, '+96550000444');

        DB::table('whats_app_chatbot_segments')->insert([
            'code' => 'active_restaurants',
            'name_ar' => 'مطاعم نشطة',
            'name_en' => 'Active restaurants',
            'enabled' => true,
            'priority' => 10,
            'rules' => json_encode(['customer_type' => 'b2b', 'min_orders' => 0, 'dormant_days' => 0]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'PILOT',
            'pilot_segment_codes' => json_encode(['active_restaurants']),
            'emergency_stop' => false,
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => ['+96550000444'],
        ])->save();

        $this->assertTrue(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));

        DB::table('whats_app_chatbot_settings')->update([
            'pilot_segment_codes' => json_encode(['different_segment']),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));
    }

    public function test_interactive_meta_reply_is_normalized_into_chatbot_action_id(): void
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'provider_type' => 'meta_cloud',
            'normalized_phone_number' => '+96522220000',
        ]);

        $events = app(MetaCloudWhatsAppProvider::class)->normalizeWebhook($profile, [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['display_phone_number' => '+96522220000'],
                        'messages' => [[
                            'from' => '96550000111',
                            'id' => 'wamid.interactive.1',
                            'timestamp' => '1791945600',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list_reply',
                                'list_reply' => [
                                    'id' => 'chatbot:add_product:42',
                                    'title' => 'Burger',
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('Burger', $events[0]->body);
        $this->assertSame('chatbot:add_product:42', $events[0]->metadata['interactive_id']);
        $this->assertSame('add_product', app(ChatbotIntentService::class)->classify(
            $events[0]->body,
            $events[0]->metadata,
        )['intent']);

        $action = app(ChatbotIntentService::class)->interpret($events[0]->body, $events[0]->metadata);
        $this->assertSame('add_product', $action->intent);
        $this->assertSame('update_order_draft', $action->requestedAction);
        $this->assertTrue($action->requiresConfirmation);
        $this->assertSame(42, $action->entities['product_id']);
    }

    public function test_order_tracking_result_is_the_visible_meta_interactive_body(): void
    {
        $phone = '+96550000777';
        $storeId = $this->store('B2B', 'WA-BOT-TRACK');
        $customerId = $this->customer($storeId, 'b2b', $phone);
        $conversationId = $this->conversation($customerId, $storeId, $phone);
        $legacyCustomerId = (int) DB::table('platform_customers')
            ->where('id', $customerId)
            ->value('legacy_customer_id');

        DB::table('orders')->insert([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'order_number' => 'WA-TRACK-001',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'KWD',
            'subtotal' => 12.500,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 12.500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'mode' => 'hybrid',
            'default_locale' => 'en',
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => [$phone],
        ])->save();

        $handled = app(ChatbotAutomationService::class)->handleInbound(
            $conversationId,
            $customerId,
            'Track order',
            ['interactive_id' => 'chatbot:order_tracking'],
        );

        $this->assertTrue($handled);

        $message = DB::table('whats_app_messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outbound')
            ->orderByDesc('id')
            ->firstOrFail();
        $metadata = json_decode((string) $message->attachment_metadata, true, 32, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('#WA-TRACK-001', (string) $message->body);
        $this->assertSame($message->body, $metadata['interactive']['body']['text']);
        $this->assertSame('list', $metadata['interactive']['type']);
        $this->assertNotEmpty($metadata['interactive']['action']['sections'][0]['rows']);
    }

    public function test_guided_product_choice_updates_real_customer_cart_as_a_draft(): void
    {
        $storeId = $this->store('B2B', 'WA-BOT-CART');
        $customerId = $this->customer($storeId, 'b2b', '+96550000333');
        $conversationId = $this->conversation($customerId, $storeId, '+96550000333');
        $productId = $this->product($storeId, 8.500);

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'default_locale' => 'en',
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => ['+96550000333'],
        ])->save();

        $handled = app(ChatbotAutomationService::class)->handleInbound(
            $conversationId,
            $customerId,
            'Burger',
            ['interactive_id' => 'chatbot:add_product:'.$productId],
        );

        $legacyId = DB::table('platform_customers')->where('id', $customerId)->value('legacy_customer_id');
        $cartId = DB::table('carts')
            ->where('store_id', $storeId)
            ->where('customer_id', $legacyId)
            ->value('id');

        $this->assertTrue($handled);
        $this->assertNotNull($cartId);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cartId,
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price_snapshot' => 8.500,
        ]);
        $this->assertSame(0, DB::table('orders')->where('customer_id', $legacyId)->count());
    }

    public function test_support_choice_creates_trackable_case_and_pauses_bot_for_handoff(): void
    {
        $storeId = $this->store('B2B', 'WA-BOT-SUPPORT');
        $customerId = $this->customer($storeId, 'b2b', '+96550000555');
        $conversationId = $this->conversation($customerId, $storeId, '+96550000555');

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'default_locale' => 'en',
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => ['+96550000555'],
        ])->save();

        $this->assertTrue(app(ChatbotAutomationService::class)->handleInbound(
            $conversationId,
            $customerId,
            'Support',
            ['interactive_id' => 'chatbot:human_handoff_missing'],
        ));

        $this->assertDatabaseHas('customer_service_cases', [
            'conversation_id' => $conversationId,
            'platform_customer_id' => $customerId,
            'type' => 'missing',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('whats_app_chatbot_sessions', [
            'conversation_id' => $conversationId,
            'state' => 'HANDOFF',
            'human_takeover' => 1,
        ]);
        $this->assertFalse(app(ChatbotRuntimePolicy::class)->canHandleConversation($conversationId));
    }

    public function test_campaign_reply_is_attributed_to_the_live_chatbot_flow(): void
    {
        $storeId = $this->store('B2B', 'WA-BOT-CAMPAIGN');
        $customerId = $this->customer($storeId, 'b2b', '+96550000666');
        $conversationId = $this->conversation($customerId, $storeId, '+96550000666');

        DB::table('whats_app_chatbot_settings')->update([
            'enabled' => true,
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'default_locale' => 'en',
            'working_from' => null,
            'working_to' => null,
            'updated_at' => now(),
        ]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::INBOUND->value => true,
                Capability::OUTBOUND->value => true,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => ['+96550000666'],
        ])->save();

        $templateId = (int) DB::table('whats_app_templates')->insertGetId([
            'name' => 'Chatbot offer',
            'provider_name' => 'chatbot_offer',
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'approved',
            'body_preview' => 'Order now',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campaignId = (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => 'Chatbot Campaign',
            'template_id' => $templateId,
            'status' => 'completed',
            'segment' => json_encode(['all' => true]),
            'test_mode' => true,
            'batch_size' => 50,
            'per_minute_limit' => 100,
            'max_recipients' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('whats_app_campaign_recipients')->insert([
            'campaign_id' => $campaignId,
            'platform_customer_id' => $customerId,
            'phone' => '+96550000666',
            'snapshot' => json_encode(['phone' => '+96550000666']),
            'eligibility_status' => 'eligible',
            'send_status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $flowId = (int) DB::table('whats_app_chatbot_flows')->insertGetId([
            'code' => 'campaign_order',
            'name_ar' => 'طلب الحملة',
            'name_en' => 'Campaign order',
            'status' => 'published',
            'version' => 1,
            'definition' => json_encode([
                'schema' => 1,
                'start' => 'welcome',
                'nodes' => [
                    ['id' => 'welcome', 'type' => 'message', 'content' => ['ar' => 'ابدأ', 'en' => 'Start'], 'next' => 'primary_action'],
                    ['id' => 'primary_action', 'type' => 'action', 'action' => 'new_order', 'requires_confirmation' => true],
                ],
            ]),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(app(ChatbotAutomationService::class)->handleInbound(
            $conversationId,
            $customerId,
            'new order',
        ));

        $event = DB::table('whats_app_chatbot_events')
            ->where('event_type', 'campaign_chatbot_entry')
            ->where('conversation_id', $conversationId)
            ->first();

        $this->assertNotNull($event);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame($campaignId, $payload['campaign_id']);
        $this->assertSame($flowId, $payload['flow_id']);
    }

    public function test_chatbot_schema_is_additive_and_contains_case_audit_and_flow_versioning(): void
    {
        foreach ([
            'whats_app_chatbot_settings',
            'whats_app_chatbot_features',
            'whats_app_chatbot_flows',
            'whats_app_chatbot_flow_versions',
            'whats_app_chatbot_segments',
            'whats_app_chatbot_sessions',
            'customer_service_cases',
            'whats_app_chatbot_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumn('whats_app_chatbot_settings', 'lifecycle'));
        $this->assertTrue(Schema::hasColumn('whats_app_chatbot_settings', 'pilot_segment_codes'));
        $this->assertTrue(Schema::hasColumn('whats_app_chatbot_events', 'actor_user_id'));
        $this->assertTrue(Schema::hasColumn('customer_service_cases', 'public_id'));
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create([
            'name' => 'Chatbot Admin',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
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

    private function customer(int $storeId, string $channel, string $phone): int
    {
        $user = User::factory()->create([
            'name' => 'Chatbot Customer',
            'email' => 'chatbot-'.Str::uuid().'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => 'Chatbot Customer',
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => 'Chatbot Customer',
            'phone' => $phone,
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conversation(?int $customerId, ?int $storeId, string $phone): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customerId,
            'external_participant_phone' => $phone,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_CUSTOMER',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, float $price): int
    {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'BOT-EA-'.Str::lower(Str::random(5)),
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'unit_id' => $unitId,
            'sku' => 'BOT-'.Str::upper(Str::random(8)),
            'name' => 'Burger Patty',
            'description' => 'FOODEX chatbot test product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }
}
