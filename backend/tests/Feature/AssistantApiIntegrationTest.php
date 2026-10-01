<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantAction;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AssistantApiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['assistant.enabled' => true, 'assistant.read_only' => true]);
    }

    public function test_feature_disabled_and_unauthorized_requests_fail_closed(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        config(['assistant.enabled' => false]);
        $this->actingAs($admin)->getJson('/admin/assistant/bootstrap')->assertNotFound();

        config(['assistant.enabled' => true]);
        $plain = User::query()->create([
            'name' => 'No Assistant',
            'email' => 'no-assistant-api@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->actingAs($plain)->getJson('/admin/assistant/bootstrap')->assertForbidden();
    }

    public function test_chat_round_trip_persists_state_executes_registered_tool_and_filters_actions(): void
    {
        $store = $this->store('ASSISTANT-API-A');
        $admin = $this->globalUser('SUPER_ADMIN', 'en');
        $this->fakeBrainAndTool();

        $created = $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
            'context' => ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $store],
        ])->assertCreated();

        $publicId = (string) $created->json('data.conversation.public_id');

        $response = $this->actingAs($admin)->postJson("/admin/assistant/conversations/{$publicId}/messages", [
            'message' => 'Sales today',
            'locale' => 'en',
            'context' => ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $store],
        ])->assertOk();

        $response
            ->assertJsonPath('data.assistant_message.intent', 'sales.summary')
            ->assertJsonPath('data.assistant_message.data.orders', 2)
            ->assertJsonPath('data.assistant_message.cards.0.data.recognized_revenue', 20)
            ->assertJsonPath('data.assistant_message.actions.0.url', '/admin/reports');

        $this->assertCount(1, $response->json('data.assistant_message.actions'));
        $this->assertDatabaseCount('assistant_messages', 2);
        $conversation = AssistantConversation::query()->where('public_id', $publicId)->firstOrFail();
        $this->assertDatabaseHas('assistant_conversation_state', [
            'conversation_id' => $conversation->getKey(),
            'last_intent' => 'sales.summary',
            'last_tool' => 'sales.summary',
        ]);

        $state = DB::table('assistant_conversation_state')->where('conversation_id', $conversation->getKey())->first();
        $authorized = json_decode((string) $state->last_authorized_entities, true);
        $this->assertSame($store, $authorized['store_id']);
        $this->assertSame('b2c', $authorized['channel']);

        $this->actingAs($admin)->getJson("/admin/assistant/conversations/{$publicId}/messages")
            ->assertOk()->assertJsonCount(2, 'data.messages');
        $this->actingAs($admin)->getJson('/admin/assistant/conversations')
            ->assertOk()->assertJsonPath('data.conversations.0.public_id', $publicId);
        $this->actingAs($admin)->postJson("/admin/assistant/conversations/{$publicId}/clear")
            ->assertOk()->assertJsonCount(0, 'data.messages');
        $this->assertDatabaseCount('assistant_messages', 0);
        $this->actingAs($admin)->deleteJson("/admin/assistant/conversations/{$publicId}")->assertNoContent();
        $this->assertDatabaseMissing('assistant_conversations', ['public_id' => $publicId]);
    }

    public function test_forged_store_page_context_is_rejected(): void
    {
        $storeA = $this->store('ASSISTANT-API-SCOPE-A');
        $storeB = $this->store('ASSISTANT-API-SCOPE-B');
        $admin = $this->storeUser($storeA, 'B2C_STORE_ADMIN');

        $this->actingAs($admin)->postJson('/admin/assistant/conversations', [
            'locale' => 'en',
            'context' => ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $storeB],
        ])->assertNotFound();
    }

    public function test_assistant_named_rate_limit_is_enforced_per_user(): void
    {
        config(['assistant.rate_limit' => 1]);
        $admin = $this->globalUser('SUPER_ADMIN');
        RateLimiter::clear('assistant:user:'.$admin->getKey());

        $this->actingAs($admin)->getJson('/admin/assistant/bootstrap')->assertOk();
        $this->actingAs($admin)->getJson('/admin/assistant/bootstrap')->assertStatus(429);
    }

    private function fakeBrainAndTool(): void
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
                        'last_period' => ['key' => 'today', 'start' => '2026-10-01T00:00:00+03:00', 'end' => '2026-10-02T00:00:00+03:00'],
                        'period' => ['key' => 'today', 'start' => '2026-10-01T00:00:00+03:00', 'end' => '2026-10-02T00:00:00+03:00'],
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
                return new AssistantToolResult(
                    data: ['orders' => 2, 'recognized_revenue' => 20, 'store_id' => $request->storeId],
                    actions: [
                        new AssistantAction('Open reports', 'admin.reports.index'),
                        new AssistantAction('Unsafe unrelated route', 'admin.security.index'),
                    ],
                );
            }
        };

        $this->app->instance(AssistantBrainInterface::class, $brain);
        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry([$tool]));
    }

    private function globalUser(string $roleCode, string $locale = 'ar'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@assistant-api.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@assistant-api.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
