<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailWhatsAppInboxSidebarDiscoverabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_store_scoped_support_can_discover_and_open_existing_inbox_in_both_locales(): void
    {
        $this->enableInbox();
        $own = $this->retailStore('V2-2529-OWN');
        $other = $this->retailStore('V2-2529-OTHER');
        $actor = $this->storeSupport($own);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $service = $groups->firstWhere('key', 'customer_service');

            $this->assertIsArray($service);
            $this->assertSame('admin.nav_groups.customer_service', $service['label']);
            $items = collect($groups->flatMap(static fn (array $group): array => $group['children']))
                ->where('key', 'whatsapp_inbox')->values();

            $this->assertCount(1, $items);
            $this->assertSame('admin.whatsapp-inbox.index', $items->first()['route']);
            $this->assertSame('whatsapp.inbox_title', $items->first()['label']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.whatsapp-inbox.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.nav_groups.customer_service'))
                ->assertSee(__('whatsapp.inbox_title'));

            // A Sidebar link to this actor's legitimate store-scoped Inbox
            // never grants access to platform or another Retail store.
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $other]))
                ->assertNotFound();
        }
    }

    public function test_global_supervisor_keeps_the_same_single_canonical_inbox_destination(): void
    {
        $this->enableInbox();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_SUPERVISOR')->firstOrFail());

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $item = $groups->flatMap(static fn (array $group): array => $group['children'])
            ->where('key', 'whatsapp_inbox')->values();

        $this->assertCount(1, $item);
        $this->assertSame('admin.whatsapp-inbox.index', $item->first()['route']);
        $this->actingAs($actor)->get(route('admin.whatsapp-inbox.index'))->assertOk();
    }

    public function test_disabled_inbox_and_roleless_actors_cannot_gain_discoverability_or_direct_access(): void
    {
        $own = $this->retailStore('V2-2529-DISABLED');
        $support = $this->storeSupport($own);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($support))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'whatsapp_inbox'));
        $this->actingAs($support)->get(route('admin.whatsapp-inbox.index'))->assertNotFound();

        $this->enableInbox();
        $unassigned = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($unassigned))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'whatsapp_inbox'));
        $this->actingAs($unassigned)->get(route('admin.whatsapp-inbox.index'))->assertForbidden();
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

    private function storeSupport(int $storeId): User
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
