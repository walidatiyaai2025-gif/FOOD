<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\AgentAssignmentEngine;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 T087R01: a visible WhatsApp inbox is not a grant to read, reply to, or
 * assign another store's conversation; the server owns each action decision.
 */
final class V2WhatsAppServiceRoleHttpBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->enableInbox();
    }

    public function test_authorized_supervisor_can_view_reply_and_assign_with_audited_state_in_both_locales(): void
    {
        $store = $this->store('B2B', 'V2-2821-WHOLESALE');
        $customer = $this->customer($store, 'b2b', 'V2 2821 Buyer');
        $conversation = $this->conversation($customer->getKey(), $store);
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', 5, true);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $supervisor->forceFill(['locale' => $locale])->save();

            $this->actingAs($supervisor)
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        $supervisor->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($supervisor)
            ->post(route('admin.whatsapp-inbox.reply', ['conversation' => $conversation]), [
                'body' => 'V2 2821 authorized response',
            ])
            ->assertRedirect(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]));

        $this->assertDatabaseHas('whats_app_messages', [
            'conversation_id' => $conversation,
            'direction' => 'outbound',
            'actor_user_id' => $supervisor->getKey(),
            'body' => 'V2 2821 authorized response',
        ]);
        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'status' => 'WAITING_FOR_CUSTOMER',
            'lock_version' => 1,
        ]);

        $supervisor->forceFill(['locale' => 'en'])->save();
        $this->actingAs($supervisor)
            ->post(route('admin.whatsapp-inbox.assign', ['conversation' => $conversation]), [
                'agent_id' => $agent->getKey(),
                'expected_version' => 1,
                'reason' => 'V2 2821 authorized assignment',
            ])
            ->assertRedirect(route('admin.whatsapp-inbox.index', ['conversation' => $conversation]));

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'primary_agent_id' => $agent->getKey(),
            'status' => 'OPEN',
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('whats_app_assignment_history', [
            'conversation_id' => $conversation,
            'actor_user_id' => $supervisor->getKey(),
            'new_primary_agent_id' => $agent->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.customer_assigned']);
    }

    public function test_store_scoped_retail_agent_cannot_view_reply_to_or_assign_foreign_store_conversation(): void
    {
        $own = $this->store('B2C', 'V2-2821-OWN');
        $foreign = $this->store('B2C', 'V2-2821-FOREIGN');
        $ownCustomer = $this->customer($own, 'b2c', 'V2 2821 Own');
        $foreignCustomer = $this->customer($foreign, 'b2c', 'V2 2821 Foreign');
        $ownConversation = $this->conversation($ownCustomer->getKey(), $own);
        $foreignConversation = $this->conversation($foreignCustomer->getKey(), $foreign);

        $actor = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $own);
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');
        app(AgentAssignmentEngine::class)->setStatus($agent, 'ONLINE', 5, true);

        $messagesBefore = DB::table('whats_app_messages')->count();
        $assignmentsBefore = DB::table('whats_app_assignment_history')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $ownConversation]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $foreignConversation]))
                ->assertForbidden();

            $reply = $this->actingAs($actor)
                ->post(route('admin.whatsapp-inbox.reply', ['conversation' => $foreignConversation]), [
                    'body' => 'Must never be sent',
                ]);
            $this->assertContains($reply->getStatusCode(), [403, 404]);

            $assignment = $this->actingAs($actor)
                ->post(route('admin.whatsapp-inbox.assign', ['conversation' => $foreignConversation]), [
                    'agent_id' => $agent->getKey(),
                    'expected_version' => 0,
                    'reason' => 'Must never be assigned',
                ]);
            $this->assertContains($assignment->getStatusCode(), [403, 404]);
        }

        $this->assertSame($messagesBefore, DB::table('whats_app_messages')->count());
        $this->assertSame($assignmentsBefore, DB::table('whats_app_assignment_history')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $foreignConversation,
            'primary_agent_id' => null,
            'lock_version' => 0,
        ]);
        $this->assertDatabaseMissing('whats_app_messages', [
            'conversation_id' => $foreignConversation,
            'body' => 'Must never be sent',
        ]);
    }

    public function test_roleless_dashboard_actor_cannot_forge_whatsapp_navigation_or_action_rights(): void
    {
        $store = $this->store('B2B', 'V2-2821-DENY');
        $customer = $this->customer($store, 'b2b', 'V2 2821 Denied');
        $conversation = $this->conversation($customer->getKey(), $store);
        $roleless = User::factory()->create(['is_active' => true]);
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');
        $messagesBefore = DB::table('whats_app_messages')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $roleless->forceFill(['locale' => $locale])->save();

            $this->actingAs($roleless)
                ->get(route('admin.whatsapp-inbox.index'))
                ->assertForbidden();

            $this->actingAs($roleless)
                ->post(route('admin.whatsapp-inbox.reply', ['conversation' => $conversation]), [
                    'body' => 'Forged unprivileged response',
                ])
                ->assertForbidden();

            $this->actingAs($roleless)
                ->post(route('admin.whatsapp-inbox.assign', ['conversation' => $conversation]), [
                    'agent_id' => $agent->getKey(),
                    'expected_version' => 0,
                ])
                ->assertForbidden();
        }

        $this->assertSame($messagesBefore, DB::table('whats_app_messages')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
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

    private function conversation(int $customer, int $storeId): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customer,
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

    private function globalUser(string $code): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function storeUser(string $code, int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $code)->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
