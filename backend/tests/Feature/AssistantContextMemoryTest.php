<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AiPrincipalContext;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantConversationState;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Support\AssistantContextToken;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantContextMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config([
            'assistant.enabled' => true,
            'assistant.read_only' => true,
            'assistant.external_ai.enabled' => false,
            'assistant.retention_days' => 1,
            'assistant.session_minutes' => 5,
            'assistant.context_token_minutes' => 5,
        ]);
    }

    public function test_signed_page_context_is_tamper_evident_user_bound_and_localized(): void
    {
        $store = $this->store('AI-CONTEXT-A');
        $admin = $this->globalUser('SUPER_ADMIN', 'ar');

        $created = $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'ar',
            'context' => [
                'path' => '/admin/b2c/dashboard?tab=summary',
                'channel' => 'b2c',
                'store_id' => $store,
            ],
        ])->assertCreated();

        $created
            ->assertJsonPath('data.context.route', 'admin.b2c')
            ->assertJsonPath('data.context.domain', 'retail')
            ->assertJsonPath('data.context.store_id', $store)
            ->assertJsonPath('data.context.locale', 'ar')
            ->assertJsonPath('data.context.direction', 'rtl')
            ->assertJsonPath('data.context.business_scope', 'platform');

        $this->assertContains('SUPER_ADMIN', $created->json('data.context.role_codes'));

        $token = (string) $created->json('data.context_token');
        $publicId = (string) $created->json('data.conversation.public_id');
        $tampered = substr($token, 0, -1).(substr($token, -1) === 'a' ? 'b' : 'a');

        $this->actingAs($admin)->postJson("/admin/assistant/conversations/{$publicId}/messages", [
            'message' => 'مبيعات اليوم',
            'context_token' => $tampered,
        ])->assertUnprocessable();

        $other = $this->globalUser('SUPER_ADMIN', 'en');
        $otherConversation = $this->actingAs($other)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
        ])->assertCreated();
        $otherPublicId = (string) $otherConversation->json('data.conversation.public_id');

        $this->actingAs($other)->postJson("/admin/assistant/conversations/{$otherPublicId}/messages", [
            'message' => 'Sales today',
            'context_token' => $token,
        ])->assertUnprocessable();
    }

    public function test_signed_context_transports_entity_hints_for_server_revalidation(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'en');
        $principal = new AiPrincipalContext(
            actorUserId: (int) $admin->getKey(),
            locale: 'en',
            roleCodes: ['SUPER_ADMIN'],
            permissionCodes: ['assistant.use'],
            businessScope: 'platform',
            channel: 'b2b',
            activeStoreId: 17,
            permittedStoreIds: [17],
            pageContext: [
                'path' => '/admin/b2b/accounts/44',
                'route' => 'admin.b2b',
                'domain' => 'wholesale',
                'channel' => 'b2b',
                'store_id' => 17,
                'authorized_entities' => [
                    'account_id' => 44,
                    'customer_id' => 31,
                ],
            ],
            retailerDataGrants: [],
            correlationId: '00000000-0000-4000-8000-000000000001',
        );

        $signed = app(AssistantContextToken::class)->issue($principal);
        $transport = app(AssistantContextToken::class)->pageContext($admin, $signed['token']);

        $this->assertSame('/admin/b2b/accounts/44', $transport['path']);
        $this->assertSame('b2b', $transport['channel']);
        $this->assertSame(17, $transport['store_id']);
        $this->assertSame(44, $transport['account_id']);
        $this->assertSame(31, $transport['customer_id']);
        $this->assertArrayNotHasKey('authorized_entities', $transport);
    }

    public function test_memory_redacts_secrets_keeps_only_safe_preferences_and_never_stores_tool_facts(): void
    {
        $store = $this->store('AI-MEMORY-A');
        $admin = $this->globalUser('SUPER_ADMIN', 'en');
        $this->fakeSalesBrainAndTool();

        $created = $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
            'context' => [
                'path' => '/admin/b2c/dashboard',
                'channel' => 'b2c',
                'store_id' => $store,
            ],
            'preferences' => [
                'response_style' => 'concise',
                'api_key' => 'must-not-persist',
            ],
        ])->assertCreated();

        $token = (string) $created->json('data.context_token');
        $publicId = (string) $created->json('data.conversation.public_id');

        $response = $this->actingAs($admin)->postJson("/admin/assistant/conversations/{$publicId}/messages", [
            'message' => 'Sales today password=hunter2 Authorization: Bearer abcdefghijklmnop',
            'locale' => 'en',
            'context_token' => $token,
        ])->assertOk();

        $response
            ->assertJsonPath('data.assistant_message.data.orders', 2)
            ->assertJsonPath('data.assistant_message.data.recognized_revenue', 20)
            ->assertJsonPath('data.context.direction', 'ltr');

        $conversation = AssistantConversation::query()->where('public_id', $publicId)->firstOrFail();
        $userMessage = AssistantMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('role', AssistantMessage::ROLE_USER)
            ->firstOrFail();

        $this->assertStringContainsString('[REDACTED]', $userMessage->content);
        $this->assertStringNotContainsString('hunter2', $userMessage->content);
        $this->assertStringNotContainsString('abcdefghijklmnop', $userMessage->content);
        $this->assertStringNotContainsString('hunter2', (string) $conversation->fresh()->title);

        $state = AssistantConversationState::query()
            ->where('conversation_id', $conversation->getKey())
            ->firstOrFail();

        $this->assertSame([
            'locale' => 'en',
            'response_style' => 'concise',
        ], $state->safe_preferences);

        $serialized = json_encode($state->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-persist', $serialized);
        $this->assertStringNotContainsString('recognized_revenue', $serialized);
        $this->assertStringNotContainsString('hunter2', $serialized);
    }

    public function test_expired_session_state_is_discarded_before_follow_up_resolution(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'en');
        $this->bindSessionProbeBrain();

        $created = $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
            'preferences' => ['response_style' => 'detailed'],
        ])->assertCreated();

        $publicId = (string) $created->json('data.conversation.public_id');
        $token = (string) $created->json('data.context_token');
        $conversation = AssistantConversation::query()->where('public_id', $publicId)->firstOrFail();

        AssistantConversationState::query()
            ->where('conversation_id', $conversation->getKey())
            ->update([
                'last_intent' => 'sales.summary',
                'last_period' => json_encode([
                    'key' => 'this_month',
                    'start' => '2026-10-01T00:00:00+03:00',
                    'end' => '2026-11-01T00:00:00+03:00',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now()->subMinutes(10),
            ]);

        $response = $this->actingAs($admin)->postJson("/admin/assistant/conversations/{$publicId}/messages", [
            'message' => 'hello',
            'locale' => 'en',
            'context_token' => $token,
        ])->assertOk();

        $response->assertJsonPath('data.assistant_message.content', 'FRESH_SESSION');

        $state = AssistantConversationState::query()
            ->where('conversation_id', $conversation->getKey())
            ->firstOrFail();

        $this->assertNull($state->last_intent);
        $this->assertNull($state->last_period);
        $this->assertSame([
            'locale' => 'en',
            'response_style' => 'detailed',
        ], $state->safe_preferences);
    }

    public function test_retention_prunes_only_the_current_users_expired_conversations(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'en');
        $other = $this->globalUser('SUPER_ADMIN', 'en');

        $old = $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
        ])->assertCreated();
        $oldPublicId = (string) $old->json('data.conversation.public_id');

        $otherCreated = $this->actingAs($other)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
        ])->assertCreated();
        $otherPublicId = (string) $otherCreated->json('data.conversation.public_id');

        AssistantConversation::query()
            ->whereIn('public_id', [$oldPublicId, $otherPublicId])
            ->update(['updated_at' => now()->subDays(2)]);

        $this->actingAs($admin)->getJson('/admin/assistant/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.conversation', null);

        $this->assertDatabaseMissing('assistant_conversations', ['public_id' => $oldPublicId]);
        $this->assertDatabaseHas('assistant_conversations', ['public_id' => $otherPublicId]);
    }

    private function fakeSalesBrainAndTool(): void
    {
        $brain = new class implements AssistantBrainInterface
        {
            public function respond(AssistantBrainRequest $request): AssistantBrainResult
            {
                return new AssistantBrainResult(
                    message: 'Using authoritative data.',
                    intent: 'sales.summary',
                    confidence: 1.0,
                    state: [
                        'last_intent' => 'sales.summary',
                        'last_tool' => 'sales.summary',
                        'last_period' => [
                            'key' => 'today',
                            'start' => '2026-10-18T00:00:00+03:00',
                            'end' => '2026-10-19T00:00:00+03:00',
                        ],
                        'period' => [
                            'key' => 'today',
                            'start' => '2026-10-18T00:00:00+03:00',
                            'end' => '2026-10-19T00:00:00+03:00',
                        ],
                        'entities' => [],
                        'last_authorized_entities' => $request->context['authorized_entities'] ?? [],
                        'pending_clarification' => false,
                    ],
                );
            }
        };

        $tool = new class implements AssistantToolInterface
        {
            public function key(): string
            {
                return 'sales.summary';
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                return new AssistantToolResult(data: [
                    'orders' => 2,
                    'recognized_revenue' => 20,
                    'store_id' => $request->storeId,
                ]);
            }
        };

        $this->app->instance(AssistantBrainInterface::class, $brain);
        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry([$tool]));
    }

    private function bindSessionProbeBrain(): void
    {
        $brain = new class implements AssistantBrainInterface
        {
            public function respond(AssistantBrainRequest $request): AssistantBrainResult
            {
                $staleWasVisible = ($request->state['last_intent'] ?? null) === 'sales.summary';

                return new AssistantBrainResult(
                    message: $staleWasVisible ? 'STALE_STATE_USED' : 'FRESH_SESSION',
                    intent: null,
                    confidence: 1.0,
                    state: [
                        'preserve_context' => true,
                        'pending_clarification' => false,
                        'locale' => $request->locale,
                    ],
                );
            }
        };

        $this->app->instance(AssistantBrainInterface::class, $brain);
        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry([]));
    }

    private function globalUser(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@assistant-context.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function store(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
