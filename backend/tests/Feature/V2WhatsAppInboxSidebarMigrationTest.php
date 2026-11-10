<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T356R6: relabel and relocate the existing guarded WhatsApp inbox navigation.
 * A group is never an authorization grant or a replacement for runtime readiness.
 */
final class V2WhatsAppInboxSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inbox_is_a_single_authorized_customer_service_link_in_both_locales(): void
    {
        $this->enableInbox();
        $admin = $this->globalUser('SUPER_ADMIN', 'v2-2723-admin@example.test');

        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('customer_service', $registry['items']['whatsapp_inbox']['group']);
        $this->assertSame(['whatsapp.inbox.view'], $registry['items']['whatsapp_inbox']['permissions']);

        foreach ([
            'ar' => ['group' => 'خدمة العملاء وواتساب', 'item' => 'صندوق الواتساب', 'direction' => 'rtl'],
            'en' => ['group' => 'Customer Service & WhatsApp', 'item' => 'WhatsApp Inbox', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()));
            $service = $groups->firstWhere('key', 'customer_service');
            $this->assertNotNull($service);
            $this->assertSame('admin.nav_groups.customer_service', $service['label']);
            $this->assertSame($expected['group'], __($service['label']));

            $matches = $groups
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'whatsapp_inbox')
                ->values();
            $this->assertCount(1, $matches, 'Existing inbox key must not be duplicated or renamed.');
            $this->assertCount(1, collect($service['children'])->where('key', 'whatsapp_inbox'));
            $item = $matches->first();
            $this->assertSame('admin.whatsapp-inbox.index', $item['route']);
            $this->assertSame('whatsapp.inbox_title', $item['label']);
            $this->assertSame($expected['item'], __($item['label']));
            $this->assertSame([], $item['params']);

            $this->actingAs($admin)
                ->get(route('admin.whatsapp-inbox.index'))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['group'])
                ->assertSee($expected['item']);
        }
    }

    public function test_runtime_disabled_hides_empty_group_and_direct_http_is_not_found(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'v2-2723-disabled@example.test');
        $groups = collect(app(AdminNavigation::class)->groupsFor($admin));

        $this->assertFalse($groups->contains('key', 'customer_service'));
        $this->assertFalse($groups->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'whatsapp_inbox'));

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertNotFound();
    }

    public function test_roleless_user_cannot_gain_inbox_access_from_navigation_relocation(): void
    {
        $this->enableInbox();
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $groups = collect(app(AdminNavigation::class)->groupsFor($user));

        $this->assertFalse($groups->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'whatsapp_inbox'));

        $this->actingAs($user)
            ->get(route('admin.whatsapp-inbox.index'))
            ->assertForbidden();
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

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
