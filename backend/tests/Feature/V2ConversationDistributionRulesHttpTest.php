<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Domain\WhatsApp\DistributionRuleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ConversationDistributionRulesHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        $this->enableInbox();
    }

    public function test_authorized_supervisor_can_discover_create_edit_deactivate_and_reactivate_real_store_rules_in_ar_en(): void
    {
        $store = $this->store('B2B', 'P202-WHOLESALE');
        $supervisor = $this->globalActor('CUSTOMER_SERVICE_SUPERVISOR', 'P202 Supervisor');
        $agent = $this->onlineAgent('P202 Preferred');
        $other = $this->onlineAgent('P202 Other');
        $rulesUrl = route('admin.whatsapp-supervisor.rules.index', ['store_id' => $store]);

        $this->assertContains(
            $store,
            app(DistributionRuleManager::class)->permittedStores($supervisor)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            $supervisor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($supervisor->fresh())->get(route('admin.whatsapp-supervisor.index'))
                ->assertOk()
                ->assertSee(route('admin.whatsapp-supervisor.rules.index'), false);

            $this->actingAs($supervisor->fresh())->get($rulesUrl)
                ->assertOk()
                ->assertSee('dir="'.$dir.'"', false)
                ->assertSee('data-v2-distribution-rules', false)
                ->assertSee(__('whatsapp_supervisor.distribution.title', [], $locale))
                ->assertSee($store ? 'P202-WHOLESALE' : '');
        }

        $payload = [
            'store_id' => $store,
            'name' => 'P202 Store Routing',
            'priority' => 1,
            'strategy' => 'least_loaded',
            'is_active' => 1,
            'agent_ids' => [$agent->id],
        ];
        $this->actingAs($supervisor->fresh())
            ->post(route('admin.whatsapp-supervisor.rules.store'), $payload)
            ->assertRedirect($rulesUrl)
            ->assertSessionHasNoErrors();

        $rule = DB::table('whats_app_assignment_rules')->where('name', 'P202 Store Routing')->first();
        $this->assertNotNull($rule);
        $this->assertSame($store, (int) $rule->store_id);
        $this->assertSame('whatsapp', $rule->channel);
        $this->assertSame('least_loaded', $rule->strategy);
        $this->assertSame('v2_p202', json_decode($rule->configuration, true)['managed_by']);
        $this->assertSame([$agent->id], json_decode($rule->configuration, true)['agent_ids']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.distribution_rule_created', 'user_id' => $supervisor->id]);
        $this->assertSame($agent->id, app(AgentAssignmentEngine::class)->choose($store, 'whatsapp', 'rule_based'));

        $revision = DistributionRuleManager::revision($rule);
        $this->actingAs($supervisor->fresh())->patch(route('admin.whatsapp-supervisor.rules.update', [
            'rule' => $rule->id,
        ]), [...$payload, 'name' => 'P202 Updated', 'priority' => 2, 'strategy' => 'round_robin',
            'agent_ids' => [$other->id], 'revision' => $revision])
            ->assertRedirect($rulesUrl)
            ->assertSessionHasNoErrors();

        $updated = DB::table('whats_app_assignment_rules')->where('id', $rule->id)->first();
        $this->assertSame('P202 Updated', $updated->name);
        $this->assertSame([$other->id], json_decode($updated->configuration, true)['agent_ids']);
        $this->assertSame($other->id, app(AgentAssignmentEngine::class)->choose($store, 'whatsapp', 'rule_based'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.distribution_rule_updated', 'user_id' => $supervisor->id]);

        // Reusing an old form must not silently overwrite the latest rule revision.
        $this->actingAs($supervisor->fresh())->from($rulesUrl)
            ->patch(route('admin.whatsapp-supervisor.rules.update', ['rule' => $rule->id]), [
                ...$payload, 'name' => 'P202 Stale', 'revision' => $revision,
            ])->assertSessionHasErrors('revision');
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['id' => $rule->id, 'name' => 'P202 Stale']);

        $latest = DistributionRuleManager::revision($updated);
        $this->actingAs($supervisor->fresh())->patch(route('admin.whatsapp-supervisor.rules.status', [
            'rule' => $rule->id,
        ]), ['store_id' => $store, 'is_active' => 0, 'revision' => $latest])
            ->assertRedirect($rulesUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('whats_app_assignment_rules', ['id' => $rule->id, 'is_active' => 0]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.distribution_rule_status_changed']);
        $this->assertNotSame(
            $other->id,
            app(AgentAssignmentEngine::class)->choose($store, 'whatsapp', 'rule_based'),
        );

        $disabled = DB::table('whats_app_assignment_rules')->where('id', $rule->id)->first();
        $this->actingAs($supervisor->fresh())->patch(route('admin.whatsapp-supervisor.rules.status', [
            'rule' => $rule->id,
        ]), ['store_id' => $store, 'is_active' => 1, 'revision' => DistributionRuleManager::revision($disabled)])
            ->assertRedirect($rulesUrl)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('whats_app_assignment_rules', ['id' => $rule->id, 'is_active' => 1]);
    }

    public function test_roleless_view_only_retail_and_foreign_store_requests_fail_closed_without_writes(): void
    {
        $b2b = $this->store('B2B', 'P202-SECURE');
        $b2c = $this->store('B2C', 'P202-RETAIL');
        $supervisor = $this->globalActor('CUSTOMER_SERVICE_SUPERVISOR', 'P202 Owner');
        $roleless = User::factory()->create(['is_active' => true]);
        $agent = $this->onlineAgent('P202 Agent');
        $create = route('admin.whatsapp-supervisor.rules.store');
        $payload = [
            'store_id' => $b2b, 'name' => 'Denied Rule', 'priority' => 1,
            'strategy' => 'round_robin', 'is_active' => 1, 'agent_ids' => [$agent->id],
        ];

        $this->actingAs($roleless)
            ->get(route('admin.whatsapp-supervisor.rules.index'))->assertForbidden();
        $this->actingAs($roleless)->post($create, $payload)->assertForbidden();

        // An online customer-service agent may read/reply, but cannot manage global distribution rules.
        $this->actingAs($agent)->get(route('admin.whatsapp-supervisor.rules.index'))->assertForbidden();
        $this->actingAs($agent)->post($create, $payload)->assertForbidden();

        $this->actingAs($supervisor)->get(route('admin.whatsapp-supervisor.rules.index', [
            'store_id' => $b2c,
        ]))->assertNotFound();
        $this->actingAs($supervisor)->post($create, [
            ...$payload, 'store_id' => $b2c,
        ])->assertNotFound();
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['name' => 'Denied Rule']);

        $this->actingAs($supervisor)->post($create, [
            ...$payload, 'agent_ids' => [$roleless->id],
        ])->assertSessionHasErrors('agent_ids');
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['name' => 'Denied Rule']);
    }

    public function test_explicit_store_view_deny_blocks_distribution_rule_administration_without_side_effects(): void
    {
        $store = $this->store('B2B', 'P202-VIEW-DENY');
        $supervisor = $this->globalActor('CUSTOMER_SERVICE_SUPERVISOR', 'P202 Denied Supervisor');
        $this->assertTrue($supervisor->hasPermission('whatsapp.conversations.assign', $store));
        $this->assertTrue($supervisor->hasPermission('whatsapp.customer_service.supervise', $store));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $supervisor->id,
            'permission_id' => Permission::query()->where('code', 'whatsapp.conversations.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $supervisor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($supervisor->fresh()->hasPermission('whatsapp.conversations.view', $store));
        $this->actingAs($supervisor->fresh())
            ->get(route('admin.whatsapp-supervisor.rules.index', ['store_id' => $store]))
            ->assertForbidden();
        $this->actingAs($supervisor->fresh())
            ->post(route('admin.whatsapp-supervisor.rules.store'), [
                'store_id' => $store,
                'name' => 'DENY attempted',
                'priority' => 1,
                'strategy' => 'round_robin',
                'is_active' => 1,
            ])->assertForbidden();
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['name' => 'DENY attempted']);
    }

    public function test_dedicated_assignment_rule_manage_deny_overrides_a_supervisor_role(): void
    {
        $store = $this->store('B2B', 'P202-MANAGE-DENY');
        $actor = $this->globalActor('CUSTOMER_SERVICE_SUPERVISOR', 'P202 Rules Denied');
        $this->assertTrue($actor->hasPermission('whatsapp.conversations.view', $store));
        $this->assertTrue($actor->hasPermission('whatsapp.conversations.assign', $store));
        $this->assertTrue($actor->hasPermission('whatsapp.assignment_rules.manage', $store));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'whatsapp.assignment_rules.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('whatsapp.assignment_rules.manage', $store));

        $this->actingAs($actor->fresh())
            ->get(route('admin.whatsapp-supervisor.rules.index', ['store_id' => $store]))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->post(route('admin.whatsapp-supervisor.rules.store'), [
                'store_id' => $store,
                'name' => 'No silent grant',
                'priority' => 1,
                'strategy' => 'round_robin',
                'is_active' => 1,
            ])->assertForbidden();
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['name' => 'No silent grant']);
    }

    public function test_legacy_advanced_rules_remain_read_only_and_disabled_module_blocks_direct_http(): void
    {
        $store = $this->store('B2B', 'P202-LEGACY');
        $supervisor = $this->globalActor('CUSTOMER_SERVICE_SUPERVISOR', 'P202 Supervisor');
        $unrelated = User::factory()->create(['name' => 'Private unrelated account', 'is_active' => true]);
        $legacyId = DB::table('whats_app_assignment_rules')->insertGetId([
            'name' => 'Legacy Conditional Queue', 'priority' => 1, 'is_active' => 1,
            'store_id' => $store, 'channel' => 'whatsapp', 'strategy' => 'round_robin',
            'conditions' => json_encode(['unsupported_legacy_predicate' => true]),
            'configuration' => json_encode(['agent_ids' => [$unrelated->id]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rule = DB::table('whats_app_assignment_rules')->where('id', $legacyId)->first();
        $url = route('admin.whatsapp-supervisor.rules.index', ['store_id' => $store]);

        $this->actingAs($supervisor)->get($url)->assertOk()
            ->assertSee('Legacy Conditional Queue')
            ->assertDontSee('Private unrelated account')
            ->assertSee(__('whatsapp_supervisor.distribution.legacy_readonly'));
        $this->actingAs($supervisor)->from($url)
            ->patch(route('admin.whatsapp-supervisor.rules.status', ['rule' => $legacyId]), [
                'store_id' => $store, 'is_active' => 0,
                'revision' => DistributionRuleManager::revision($rule),
            ])->assertSessionHasErrors('rule');
        $this->assertDatabaseHas('whats_app_assignment_rules', ['id' => $legacyId, 'is_active' => 1]);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'DISABLED', 'capabilities' => ['agent_inbox' => false],
        ])->save();

        $this->actingAs($supervisor)->get($url)->assertNotFound();
        $this->actingAs($supervisor)->post(route('admin.whatsapp-supervisor.rules.store'), [
            'store_id' => $store, 'name' => 'Disabled Rule', 'priority' => 1,
            'strategy' => 'round_robin', 'is_active' => 1,
        ])->assertNotFound();
        $this->assertDatabaseMissing('whats_app_assignment_rules', ['name' => 'Disabled Rule']);
    }

    private function enableInbox(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
        ])->save();
    }

    private function globalActor(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name, 'locale' => 'en', 'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    private function onlineAgent(string $name): User
    {
        $agent = $this->globalActor('CUSTOMER_SERVICE_AGENT', $name);
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', 10, true);

        return $agent;
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
