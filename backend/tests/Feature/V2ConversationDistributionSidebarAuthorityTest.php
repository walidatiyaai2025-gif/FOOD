<?php

namespace Tests\Feature;

use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 P201: conversation distribution is the real WhatsApp Inbox assignment
 * action, not a second route, visibility grant or mock navigation screen.
 */
final class V2ConversationDistributionSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_supervisor_discovers_the_real_inbox_and_assigns_with_audited_optimistic_locking(): void
    {
        $this->enableInbox();
        $store = $this->store('B2B', 'P201-WHOLESALE');
        $conversation = $this->conversation($this->customer($store, 'b2b', 'P201 Buyer')->getKey(), $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'P201 Supervisor');
        $first = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'P201 First Agent');
        $second = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'P201 Second Agent');

        $this->assertTrue($supervisor->hasPermission('whatsapp.conversations.assign', $store));
        $this->assertTrue($supervisor->hasPermission('whatsapp.conversations.reassign', $store));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $supervisor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $items = collect(app(AdminNavigation::class)->groupsFor($supervisor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'whatsapp_inbox')
                ->values();

            $this->assertCount(1, $items);
            $this->assertSame('admin.whatsapp-inbox.index', $items->first()['route']);
            $this->actingAs($supervisor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('whatsapp.inbox.thread_ui.manage_assignment'));
        }

        $assign = route('admin.whatsapp-inbox.assign', ['conversation' => $conversation]);
        $inbox = route('admin.whatsapp-inbox.index', ['conversation' => $conversation]);

        $this->actingAs($supervisor->fresh())
            ->patch($assign, [
                'agent_id' => $first->getKey(),
                'expected_version' => 0,
                'reason' => 'P201 queue assignment',
            ])
            ->assertRedirect($inbox);

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $first->getKey(),
            'lock_version' => 1,
        ]);
        $this->assertDatabaseHas('whats_app_assignment_history', [
            'conversation_id' => $conversation,
            'new_primary_agent_id' => $first->getKey(),
            'reason' => 'P201 queue assignment',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.customer_assigned']);

        // A concurrent/stale writer must not overwrite the first assignment.
        $this->actingAs($supervisor->fresh())
            ->patch($assign, [
                'agent_id' => $second->getKey(),
                'expected_version' => 0,
            ])
            ->assertSessionHasErrors('conversation');

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $first->getKey(),
            'lock_version' => 1,
        ]);
        $this->assertSame(
            1,
            DB::table('whats_app_assignment_history')->where('conversation_id', $conversation)->count(),
        );

        $this->actingAs($supervisor->fresh())
            ->patch($assign, [
                'agent_id' => $second->getKey(),
                'expected_version' => 1,
                'reason' => 'P201 supervisor reassignment',
            ])
            ->assertRedirect($inbox);

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $second->getKey(),
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('whats_app_assignment_history', [
            'conversation_id' => $conversation,
            'new_primary_agent_id' => $second->getKey(),
            'reason' => 'P201 supervisor reassignment',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.customer_reassigned']);
        $this->assertSame(
            2,
            DB::table('whats_app_assignment_history')->where('conversation_id', $conversation)->count(),
        );
    }

    public function test_foreign_retail_agent_cannot_be_assigned_to_another_store_conversation(): void
    {
        $this->enableInbox();
        $own = $this->store('B2C', 'P201-RETAIL-A');
        $foreign = $this->store('B2C', 'P201-RETAIL-B');
        $conversation = $this->conversation($this->customer($own, 'b2c', 'P201 Retail Buyer')->getKey(), $own);
        // The authorized writer must belong to the conversation's Retail store.
        // A wholesale-only global supervisor is not a B2C assignment actor.
        $storeSupervisor = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $own);
        $foreignAgent = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $foreign);

        $this->assertTrue($storeSupervisor->hasPermission('whatsapp.conversations.assign', $own));
        $this->assertFalse($storeSupervisor->hasPermission('whatsapp.conversations.assign', $foreign));
        $this->assertFalse($foreignAgent->hasPermission('whatsapp.conversations.reply', $own));

        $this->actingAs($storeSupervisor)->patch(route('admin.whatsapp-inbox.assign', [
            'conversation' => $conversation,
        ]), [
            'agent_id' => $foreignAgent->getKey(),
            'expected_version' => 0,
        ])->assertSessionHasErrors('agent_id');

        // An actor with only global wholesale Customer Service privileges is
        // rejected *before* any Retail recipient selection or optimistic write.
        $wholesaleSupervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'P201 Wholesale Supervisor');
        $this->assertFalse($wholesaleSupervisor->hasPermission('whatsapp.conversations.assign', $own));
        $this->actingAs($wholesaleSupervisor)->patch(route('admin.whatsapp-inbox.assign', [
            'conversation' => $conversation,
        ]), [
            'agent_id' => $foreignAgent->getKey(),
            'expected_version' => 0,
        ])->assertForbidden();

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => null,
            'lock_version' => 0,
        ]);
        $this->assertDatabaseMissing('whats_app_assignment_history', [
            'conversation_id' => $conversation,
        ]);
    }

    public function test_roleless_and_disabled_module_cannot_use_assignment_directly(): void
    {
        $store = $this->store('B2B', 'P201-DISABLED');
        $conversation = $this->conversation($this->customer($store, 'b2b', 'P201 Blocked Buyer')->getKey(), $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'P201 Disabled Supervisor');
        $assign = route('admin.whatsapp-inbox.assign', ['conversation' => $conversation]);

        $this->actingAs($supervisor)->patch($assign, [
            'agent_id' => $supervisor->getKey(),
            'expected_version' => 0,
        ])->assertNotFound();

        $this->enableInbox();
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($roleless))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'whatsapp_inbox'));

        $this->actingAs($roleless)->patch($assign, [
            'agent_id' => $supervisor->getKey(),
            'expected_version' => 0,
        ])->assertForbidden();

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => null,
            'lock_version' => 0,
        ]);
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

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
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
            'user_id' => $user->getKey(),
            'type' => $channel,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformCustomer::query()->create([
            'user_id' => $user->getKey(),
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

    private function conversation(int $platformCustomerId, int $storeId): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $platformCustomerId,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_AGENT',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => now(),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalUser(string $code, string $name): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en', 'name' => $name]);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function storeUser(string $roleCode, int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }
}
