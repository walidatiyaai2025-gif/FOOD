<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\RuntimePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P203: Campaigns Sidebar discoverability must match the real HTTP
 * WhatsApp capability middleware for all Dashboard personas.
 */
final class V2CampaignsRuntimeNavigationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_campaign_navigation_is_fail_closed_with_disabled_capability_in_both_locales(): void
    {
        $this->configureCampaigns(false);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor = $this->campaignViewer('CUSTOMER_SERVICE_AGENT', $locale);
            $this->assertTrue($actor->hasPermission('whatsapp.campaigns.view'));
            $this->assertFalse(app(RuntimePolicy::class)->allowsCampaignManagement());
            $this->assertNotContains('whatsapp_campaigns', $this->marketingKeys($actor));

            $this->actingAs($actor)
                ->get(route('admin.whatsapp-campaigns.index', [], false))
                ->assertNotFound();
        }
    }

    public function test_customer_service_campaigns_are_discoverable_only_when_http_destination_is_operational(): void
    {
        $this->configureCampaigns(true);

        foreach (['CUSTOMER_SERVICE_MANAGER', 'CUSTOMER_SERVICE_AGENT', 'CUSTOMER_SERVICE_SUPERVISOR'] as $role) {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $actor = $this->campaignViewer($role, $locale);

                $this->assertTrue(app(RuntimePolicy::class)->allowsCampaignManagement());
                $this->assertContains('whatsapp_campaigns', $this->marketingKeys($actor));
                $this->actingAs($actor)
                    ->get(route('admin.whatsapp-campaigns.index', [], false))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_emergency_stop_and_disabled_lifecycle_hide_link_without_mutating_campaigns(): void
    {
        $actor = $this->campaignViewer('CUSTOMER_SERVICE_AGENT', 'en');
        $count = DB::table('whats_app_campaigns')->count();

        $this->configureCampaigns(true, true);
        $this->assertNotContains('whatsapp_campaigns', $this->marketingKeys($actor));
        $this->actingAs($actor)
            ->get(route('admin.whatsapp-campaigns.index', [], false))
            ->assertNotFound();

        $this->configureCampaigns(true);
        WhatsAppModuleState::query()->findOrFail(1)->forceFill(['lifecycle' => 'DISABLED'])->save();
        $this->assertNotContains('whatsapp_campaigns', $this->marketingKeys($actor));
        $this->actingAs($actor)
            ->get(route('admin.whatsapp-campaigns.index', [], false))
            ->assertNotFound();

        $this->assertSame($count, DB::table('whats_app_campaigns')->count());
    }

    /** @return list<string> */
    private function marketingKeys(User $actor): array
    {
        $group = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'marketing');

        return array_values(array_column((array) ($group['children'] ?? []), 'key'));
    }

    private function campaignViewer(string $roleCode, string $locale): User
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        $permissionId = Permission::query()->where('code', 'whatsapp.campaigns.view')->firstOrFail()->id;
        // Test fixture only: isolate the runtime-capability decision from RBAC.
        $role->permissions()->syncWithoutDetaching([$permissionId]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $actor->roles()->attach($role->id);

        return $actor->fresh();
    }

    private function configureCampaigns(bool $allowed, bool $emergencyStop = false): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => $emergencyStop,
            'capabilities' => [
                'outbound' => true,
                'campaigns' => $allowed,
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => ['+96555555555'],
        ])->save();
    }
}
