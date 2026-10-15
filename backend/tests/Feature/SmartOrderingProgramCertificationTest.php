<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\RestaurantAnalyticsService;
use App\Domain\SmartOrdering\RestaurantSiteResolver;
use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use App\Domain\SmartOrdering\SmartOrderingAvailabilityService;
use App\Domain\SmartOrdering\SmartOrderingMenuReadService;
use App\Domain\SmartOrdering\SmartOrderingMerchandisingService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SupplyIntelligenceService;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use App\Domain\SmartOrdering\WhatsApp\SmartOrderingWhatsAppBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SmartOrderingProgramCertificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ga_contract_surface_is_complete_and_default_off(): void
    {
        $this->assertSame(
            ['DISABLED', 'INTERNAL_TEST', 'PILOT', 'ENABLED', 'EMERGENCY_STOP'],
            config('smart_ordering.runtime_states'),
        );

        $this->assertEqualsCanonicalizing([
            'public_menu', 'ordering', 'dine_in', 'pickup', 'delivery', 'whatsapp',
            'chatbot', 'offers', 'campaign_qr', 'analytics', 'loyalty', 'reviews',
            'advanced_upsell', 'multi_branch', 'custom_domain', 'foodex_supply_intelligence',
        ], config('smart_ordering.entitlements'));

        foreach ([
            RestaurantSiteResolver::class,
            SmartOrderingRuntimePolicy::class,
            SmartOrderingMenuReadService::class,
            SmartOrderingAvailabilityService::class,
            ThemeRegistry::class,
            SmartOrderingMerchandisingService::class,
            SmartOrderingAnalyticsService::class,
            RestaurantAnalyticsService::class,
            SmartOrderingWhatsAppBridge::class,
            SupplyIntelligenceService::class,
        ] as $service) {
            $this->assertTrue(class_exists($service), $service.' must remain available in the GA composition.');
        }

        $this->assertTrue(Route::has('public.smart-ordering.storefront'));
        $this->assertTrue(Route::has('admin.smart-ordering.orders.transition'));
        $this->assertSame(0, DB::table('smart_ordering_sites')->count(), 'Fresh installs must not auto-enable Smart Ordering.');
    }

    public function test_public_and_admin_contracts_keep_security_boundaries(): void
    {
        $public = Route::getRoutes()->getByName('public.smart-ordering.storefront');
        $admin = Route::getRoutes()->getByName('admin.smart-ordering.orders.transition');

        $this->assertNotNull($public);
        $this->assertNotNull($admin);
        $this->assertContains('throttle:120,1', $public->gatherMiddleware());
        $this->assertContains('management.dashboard', $admin->gatherMiddleware());
    }
}
