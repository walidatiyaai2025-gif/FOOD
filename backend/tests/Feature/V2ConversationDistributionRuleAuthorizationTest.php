<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Domain\WhatsApp\ConversationService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * V2 P202: automated WhatsApp rules must route only to agents who can
 * both VIEW and REPLY in the conversation's exact store.
 *
 * P201's supervisor/manual assignment and Inbox HTTP tests remain separate.
 */
final class V2ConversationDistributionRuleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_store_reply_deny_excludes_online_agent_even_when_priority_rule_prefers_them(): void
    {
        $own = $this->wholesaleStore('P202-OWN');
        $other = $this->wholesaleStore('P202-OTHER');
        $blocked = $this->onlineAgent('P202 Denied');
        $eligible = $this->onlineAgent('P202 Authorized');
        $this->denyReplyForStore($blocked, $own);

        $this->assertTrue($blocked->fresh()->hasPermission('whatsapp.conversations.view', $own));
        $this->assertFalse($blocked->fresh()->hasPermission('whatsapp.conversations.reply', $own));
        $this->assertTrue($blocked->fresh()->hasPermission('whatsapp.conversations.reply', $other));

        DB::table('whats_app_assignment_rules')->insert([
            'name' => 'P202 preferred denied agent',
            'priority' => 1,
            'is_active' => true,
            'store_id' => $own,
            'channel' => 'whatsapp',
            'strategy' => 'round_robin',
            'configuration' => json_encode(['agent_ids' => [$blocked->id]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $engine = app(AgentAssignmentEngine::class);
        $this->assertNotContains($blocked->id, $engine->eligibleAgentIds($own, 'whatsapp'));
        $this->assertContains($eligible->id, $engine->eligibleAgentIds($own, 'whatsapp'));
        $this->assertContains($blocked->id, $engine->eligibleAgentIds($other, 'whatsapp'));

        // Preferred IDs never override an effective store-scoped permission DENY.
        $this->assertSame($eligible->id, $engine->choose($own, 'whatsapp', 'rule_based'));
        $this->assertSame($eligible->id, $engine->choose($own, 'whatsapp', 'least_loaded'));
    }

    public function test_all_view_only_agents_leave_new_conversation_in_unassigned_queue(): void
    {
        $store = $this->wholesaleStore('P202-UNASSIGNED');
        $blocked = $this->onlineAgent('P202 View Only');
        $this->denyReplyForStore($blocked, $store);

        $engine = app(AgentAssignmentEngine::class);
        $this->assertSame([], $engine->eligibleAgentIds($store, 'whatsapp'));
        $this->assertNull($engine->choose($store, 'whatsapp', 'rule_based'));
        $this->assertNull($engine->choose($store, 'whatsapp', 'least_loaded'));

        $conversation = (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $store,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_AGENT',
            'primary_agent_id' => null,
            'waiting_since' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNull(app(ConversationService::class)->autoAssign($conversation));
        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => null,
            'status' => 'WAITING_FOR_AGENT',
        ]);
        $this->assertDatabaseCount('whats_app_assignment_history', 0);
    }

    public function test_retail_agent_with_reply_deny_does_not_cross_into_another_store(): void
    {
        $retailA = $this->retailStore('P202-RETAIL-A');
        $retailB = $this->retailStore('P202-RETAIL-B');
        $agent = User::factory()->create(['name' => 'P202 Retail Agent', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $agent->id,
            'store_id' => $retailA,
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', 3, true);

        $engine = app(AgentAssignmentEngine::class);
        $this->assertContains($agent->id, $engine->eligibleAgentIds($retailA, 'whatsapp'));
        $this->assertNotContains($agent->id, $engine->eligibleAgentIds($retailB, 'whatsapp'));

        $this->denyReplyForStore($agent, $retailA);
        $this->assertTrue($agent->fresh()->hasPermission('whatsapp.conversations.view', $retailA));
        $this->assertFalse($agent->fresh()->hasPermission('whatsapp.conversations.reply', $retailA));
        $this->assertNotContains($agent->id, $engine->eligibleAgentIds($retailA, 'whatsapp'));
        $this->assertNotContains($agent->id, $engine->eligibleAgentIds($retailB, 'whatsapp'));
        $this->assertNull($engine->choose($retailA, 'whatsapp'));
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_direct_agent_eligibility_fails_closed_with_existing_localized_error(): void
    {
        $store = $this->wholesaleStore('P202-SCOPE');
        $blocked = $this->onlineAgent('P202 Forbidden');
        $this->denyReplyForStore($blocked, $store);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                app(AgentAssignmentEngine::class)->assertEligible($blocked->id, $store);
                $this->fail('View-only employee was incorrectly eligible for distribution');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    [__('whatsapp.inbox.errors.agent_scope', [], $locale)],
                    $exception->errors()['agent_id'] ?? [],
                );
            }
        }

        $this->assertDatabaseCount('whats_app_assignment_history', 0);
    }

    private function wholesaleStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function onlineAgent(string $name): User
    {
        $actor = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        app(AgentAssignmentEngine::class)->setStatus($actor, 'ONLINE', 10, true);

        return $actor;
    }

    private function denyReplyForStore(User $actor, int $store): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'whatsapp.conversations.reply')->firstOrFail()->id,
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
