<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerServiceManagerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_manager_has_catalog_inventory_and_full_marketing_access_without_field_tracking(): void
    {
        $manager = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $manager->roles()->attach(
            Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail(),
        );

        foreach ([
            'catalog.view',
            'catalog.create',
            'catalog.edit',
            'catalog.manage',
            'inventory.view',
            'inventory.adjust',
            'inventory.manage',
            'promotions.view',
            'promotions.manage',
            'notifications.view',
            'notifications.manage',
            'coupons.view',
            'coupons.manage',
            'live_ads.view',
            'live_ads.manage',
            'whatsapp.campaigns.view',
            'whatsapp.campaigns.manage',
            'whatsapp.campaigns.send',
        ] as $permission) {
            $this->assertTrue($manager->hasPermission($permission), $permission.' should be granted.');
        }

        $this->assertFalse($manager->hasPermission('drivers.tracking.view'));

        $groups = collect(app(AdminNavigation::class)->groupsFor($manager))
            ->keyBy('key');

        $this->assertTrue($groups->has('catalog'));
        $catalogKeys = collect($groups->get('catalog')['children'])->pluck('key')->all();
        $this->assertContains('catalog_management', $catalogKeys);
        $this->assertContains('b2b_inventory', $catalogKeys);

        $this->assertTrue($groups->has('marketing'));
        $marketingKeys = collect($groups->get('marketing')['children'])->pluck('key')->all();
        $this->assertContains('whatsapp_campaigns', $marketingKeys);
        $this->assertContains('notification_campaigns', $marketingKeys);
        $this->assertContains('live_ads', $marketingKeys);
        $this->assertContains('coupons', $marketingKeys);
        $this->assertNotContains('whatsapp_chatbot', $marketingKeys);

        $this->assertFalse($groups->has('advertising'));
        $this->assertFalse($groups->has('field_operations'));

        $operationKeys = collect($groups->get('operations')['children'])->pluck('key')->all();
        $this->assertNotContains('driver_live_tracking', $operationKeys);
    }

    public function test_all_customer_service_profiles_hide_chatbot_and_field_operations(): void
    {
        foreach ([
            'CUSTOMER_SERVICE_MANAGER',
            'CUSTOMER_SERVICE_SUPERVISOR',
            'CUSTOMER_SERVICE_AGENT',
        ] as $roleCode) {
            $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
            $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

            $this->assertFalse($user->hasPermission('drivers.tracking.view'), $roleCode);

            $groups = collect(app(AdminNavigation::class)->groupsFor($user))
                ->keyBy('key');

            $this->assertFalse($groups->has('field_operations'), $roleCode);

            if ($groups->has('marketing')) {
                $marketingKeys = collect($groups->get('marketing')['children'])->pluck('key')->all();
                $this->assertNotContains('whatsapp_chatbot', $marketingKeys, $roleCode);
            }

            if ($groups->has('operations')) {
                $operationKeys = collect($groups->get('operations')['children'])->pluck('key')->all();
                $this->assertNotContains('driver_live_tracking', $operationKeys, $roleCode);
            }
        }
    }
}
