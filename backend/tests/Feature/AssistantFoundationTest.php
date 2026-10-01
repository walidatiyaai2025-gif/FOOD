<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantConversationState;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssistantFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_defaults_are_fail_closed_and_read_only(): void
    {
        $this->assertFalse((bool) config('assistant.enabled'));
        $this->assertTrue((bool) config('assistant.read_only'));
        $this->assertSame(90, config('assistant.retention_days'));
        $this->assertSame(60, config('assistant.cache_seconds'));
        $this->assertSame(30, config('assistant.rate_limit'));
    }

    public function test_assistant_schema_and_models_are_isolated_and_relational(): void
    {
        $this->assertTrue(Schema::hasTable('assistant_conversations'));
        $this->assertTrue(Schema::hasTable('assistant_messages'));
        $this->assertTrue(Schema::hasTable('assistant_conversation_state'));

        $user = User::query()->create([
            'name' => 'Assistant Operator',
            'email' => 'assistant-operator@example.test',
            'password' => 'password',
        ]);

        $conversation = AssistantConversation::query()->create([
            'public_id' => 'f49c7303-916d-45af-b2ec-76e315875f0c',
            'user_id' => $user->id,
            'locale' => 'ar',
        ]);

        AssistantMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => AssistantMessage::ROLE_USER,
            'content' => 'مبيعات اليوم',
        ]);

        AssistantConversationState::query()->create([
            'conversation_id' => $conversation->id,
            'last_intent' => 'sales.summary',
            'last_authorized_entities' => ['store_ids' => [5]],
            'locale' => 'ar',
        ]);

        $this->assertCount(1, $conversation->fresh()->messages);
        $this->assertSame('sales.summary', $conversation->fresh()->state?->last_intent);
        $this->assertSame(['store_ids' => [5]], $conversation->fresh()->state?->last_authorized_entities);
    }

    public function test_assistant_use_permission_follows_existing_rbac_matrix(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $this->assertDatabaseHas('permissions', ['code' => 'assistant.use']);

        foreach ([
            'SUPER_ADMIN',
            'B2B_ADMIN',
            'B2C_STORE_ADMIN',
            'OPERATIONS',
            'INVENTORY',
            'FINANCE',
            'CUSTOMER_SUPPORT',
            'RETAIL_OPERATIONS',
            'RETAIL_INVENTORY',
            'RETAIL_FINANCE',
            'RETAIL_CUSTOMER_SUPPORT',
        ] as $roleCode) {
            $this->assertTrue(
                DB::table('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                    ->where('permissions.code', 'assistant.use')
                    ->where('roles.code', $roleCode)
                    ->exists(),
                "Expected {$roleCode} to receive assistant.use.",
            );
        }

        $this->assertFalse(
            DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->where('permissions.code', 'assistant.use')
                ->whereIn('roles.code', ['B2B_DRIVER', 'B2C_DRIVER'])
                ->exists(),
        );
    }

    public function test_tool_registry_is_typed_and_rejects_duplicates(): void
    {
        $tool = new class implements AssistantToolInterface {
            public function key(): string
            {
                return 'foundation.test';
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                return new AssistantToolResult(data: ['actor_user_id' => $request->actorUserId]);
            }
        };

        $registry = new AssistantToolRegistry([$tool]);

        $this->assertTrue($registry->has('foundation.test'));
        $this->assertSame(['foundation.test'], $registry->keys());
        $this->assertSame(['actor_user_id' => 7], $registry->get('foundation.test')->execute(
            new AssistantToolRequest(actorUserId: 7, locale: 'en'),
        )->data);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register($tool);
    }
}
