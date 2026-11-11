<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
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
 * P200: Customer conversations reuse the real Inbox list, search, selection
 * and store-limited HTTP guards instead of introducing an orphan Sidebar page.
 */
final class V2CustomerConversationsInboxScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_support_can_find_own_conversation_but_cannot_search_or_select_another_store(): void
    {
        $this->enableInbox();
        $own = $this->retailStore('V2-P200-OWN');
        $foreign = $this->retailStore('V2-P200-FOREIGN');
        $ownConversation = $this->conversation($this->customer($own, 'P200 Own Buyer')->getKey(), $own);
        $foreignConversation = $this->conversation($this->customer($foreign, 'P200 Foreign Buyer')->getKey(), $foreign);
        $actor = $this->retailSupport($own);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['q' => 'P200 Own']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P200 Own Buyer')
                ->assertDontSee('P200 Foreign Buyer');

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $ownConversation]))
                ->assertOk()
                ->assertSee('P200 Own Buyer')
                ->assertDontSee('P200 Foreign Buyer');

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['q' => 'P200 Foreign']))
                ->assertOk()
                ->assertSee(__('whatsapp.inbox.no_conversations'))
                ->assertDontSee('P200 Foreign Buyer');

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['store_id' => $foreign]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['conversation' => $foreignConversation]))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $foreignConversation,
            'primary_agent_id' => null,
            'lock_version' => 0,
        ]);
    }

    public function test_empty_status_filter_shows_localized_state_without_phantom_conversation(): void
    {
        $this->enableInbox();
        $own = $this->retailStore('V2-P200-EMPTY');
        $this->conversation($this->customer($own, 'P200 Waiting Buyer')->getKey(), $own);
        $actor = $this->retailSupport($own);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index', ['status' => 'RESOLVED']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('whatsapp.inbox.no_conversations'))
                ->assertDontSee('P200 Waiting Buyer');
        }
    }

    public function test_module_disabled_and_roleless_direct_inbox_access_fail_closed(): void
    {
        $own = $this->retailStore('V2-P200-DISABLED');
        $actor = $this->retailSupport($own);

        $this->actingAs($actor)->get(route('admin.whatsapp-inbox.index'))->assertNotFound();

        $this->enableInbox();
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get(route('admin.whatsapp-inbox.index'))->assertForbidden();
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

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $name): PlatformCustomer
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->getKey(),
            'type' => 'b2c',
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
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    private function conversation(int $customerId, int $storeId): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customerId,
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

    private function retailSupport(int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
