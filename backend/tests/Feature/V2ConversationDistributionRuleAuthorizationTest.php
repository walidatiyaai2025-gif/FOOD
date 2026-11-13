<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseCount('whats_app_assignment_history', 0);
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
