<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class ErpCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        // Existing available-card fixtures are explicitly version-certified.
        config()->set('enterprise_module_readiness.certified_versions', [
            'procurement' => 2,
            'inventory_wms' => 1,
            'finance' => 1,
            'treasury' => 1,
            'enterprise_bi' => 1,
        ]);
        // Disposable signed evidence only for positive ERP Center test scenarios.
        // Production certification and trusted public keys stay empty by default.
        SyntheticErpWorkflowEvidence::install();
    }

    public function test_all_erp_workbench_denial_reasons_are_plain_language_ar_en_not_raw_state_codes(): void
    {
        // These labels are rendered for disabled/unavailable/uncertified ERP
        // workbenches. A missing translation would expose an internal key,
        // making a secure HTTP 403 misleading to a mobile or desktop user.
        $reasons = [
            'module_setup' => ['ar' => 'أكمل إعداد', 'en' => 'Complete its lifecycle setup'],
            'module_disabled' => ['ar' => 'الوحدة معطلة', 'en' => 'module is disabled'],
            'module_unavailable' => ['ar' => 'موقوفة', 'en' => 'suspended'],
            'entitlement_scope' => ['ar' => 'صلاحية إتاحة فعالة', 'en' => 'effective entitlement'],
            'missing_scope' => ['ar' => 'متجر أو نطاق كيان قانوني', 'en' => 'authorized store and legal-entity scope'],
            'uncertified' => ['ar' => 'لم تعتمد الجاهزية التشغيلية', 'en' => 'not passed operational certification'],
            'unhealthy' => ['ar' => 'فشل فحص الصحة التشغيلية', 'en' => 'failed its operational health check'],
            'route_unavailable' => ['ar' => 'مسار العمل المسجل غير متاح', 'en' => 'registered business destination is unavailable'],
        ];

        foreach ($reasons as $reason => $locales) {
            foreach ($locales as $locale => $humanExplanation) {
                $key = 'admin.erp_center.denial_reasons.'.$reason;
                $label = __($key, [], $locale);
                $this->assertNotSame($key, $label, "{$reason}/{$locale}: missing translation");
                $this->assertStringContainsString($humanExplanation, $label);
                $this->assertSame(1, preg_match(
                    $locale === 'ar' ? '/[\x{0600}-\x{06FF}]/u' : '/[A-Za-z]/',
                    $label,
                ));
                $this->assertStringNotContainsString('{', $label);
                $this->assertStringNotContainsString('manifest_sha256', $label);
            }
        }
    }

    public function test_erp_overview_replaces_whatsapp_internal_uuid_with_neutral_reference_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-work-uuid-admin@example.test');
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore('P135-ERP-UUID-PREVIEW');
        $opaqueUuid = '123e4567-e89b-42d3-a456-426614174000';
        $conversationId = DB::table('whats_app_conversations')->insertGetId([
            'public_id' => $opaqueUuid,
            'store_id' => $store->id,
            'status' => 'WAITING_FOR_AGENT',
            'primary_agent_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index'));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee($opaqueUuid);

            $item = collect($response->viewData('myWorkItems'))->firstWhere('kind', 'conversation');
            $this->assertIsArray($item);
            $this->assertSame('—', $item['reference']);
            $this->assertSame(
                route('admin.whatsapp-inbox.index', ['conversation' => $conversationId], false),
                $item['deep_link'],
            );

            $activity = collect($response->viewData('activity'))->firstWhere('kind', 'conversation');
            $this->assertIsArray($activity);
            $this->assertSame('—', $activity['reference']);
            $this->assertSame($item['deep_link'], $activity['deep_link']);
        }

        // The canonical source identifier and deep link are not rewritten:
        // only ERP Center's compact presentation is sanitized.
        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversationId,
            'public_id' => $opaqueUuid,
        ]);
    }

    public function test_explicitly_disabled_module_explains_denial_without_an_action_or_internal_payload(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-disabled-diagnostic@example.test');
        $this->app['env'] = 'production';
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true, null, $admin);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'disable', $admin);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index', ['tab' => 'modules']));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.erp_center.denial_reasons.module_disabled', [], $locale));

            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('disabled', $card['status']);
            $this->assertSame('module_disabled', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->assertSame(1, preg_match(
                '/<article class="foodex-card erp-module[^"]*" data-erp-key="procurement".*?<\/article>/s',
                (string) $response->getContent(),
                $markup,
            ));
            $this->assertStringNotContainsString('foodex-action-primary', $markup[0]);
            $this->assertStringNotContainsString('{"', $markup[0]);
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    public function test_unhealthy_certified_module_has_localized_nonactionable_diagnostics_without_probe_details(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-unhealthy-diagnostic@example.test');
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore('P135-UNHEALTHY-DIAGNOSTIC');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true, null, $admin);
        config()->set('enterprise_module_health.probes.procurement', [ErpCenterOpaqueFailureProbe::class]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index', ['tab' => 'modules']));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.erp_center.denial_reasons.unhealthy', [], $locale));

            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertSame('error', $card['health']);
            $this->assertNull($card['url']);
            $this->assertSame(1, preg_match(
                '/<article class="foodex-card erp-module[^"]*" data-erp-key="procurement".*?<\/article>/s',
                (string) $response->getContent(),
                $markup,
            ));
            $this->assertStringNotContainsString('foodex-action-primary', $markup[0]);
            $this->assertStringNotContainsString('ERP_PRIVATE_PROBE_MARKER', $markup[0]);
            $this->assertStringNotContainsString('{"', $markup[0]);
            $this->assertSame(0, preg_match(
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',
                $markup[0],
            ));

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
            ]))->assertForbidden();
        }
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    public function test_disabled_module_is_explicit_and_never_exposes_an_operational_cta(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-owner@example.test');
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response
            ->assertOk()
            ->assertSee('ERP Center')
            ->assertSee('data-erp-key="procurement" data-erp-status="setup_required"', false)
            ->assertSee('Setup required')
            ->assertSee('dir="ltr"', false);
    }

    public function test_local_super_admin_bootstraps_all_erp_modules_and_platform_entitlements(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-modules@example.test');
        // Even local bootstrap needs a real store/legal-entity for Finance.
        $store = $this->wholesaleStore('P135-LOCAL-FINANCE-STORE');

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response->assertOk();

        foreach ([
            'procurement' => 'procurement',
            'wms' => 'inventory_wms',
            'finance' => 'finance',
            'treasury' => 'treasury',
            'bi' => 'enterprise_bi',
        ] as $cardKey => $moduleCode) {
            $response->assertSee(
                'data-erp-key="'.$cardKey.'" data-erp-status="available"',
                false,
            );

            $this->assertDatabaseHas('enterprise_modules', [
                'code' => $moduleCode,
                'state' => 'ENABLED',
            ]);

            $this->assertDatabaseHas('enterprise_module_entitlements', [
                'module_code' => $moduleCode,
                'scope_key' => 'platform:0',
                'is_enabled' => true,
            ]);
        }

        $cards = collect($response->viewData('cards'))->keyBy('key');
        foreach (['procurement', 'wms', 'treasury'] as $key) {
            $this->assertIsArray($cards->get($key));
            $url = $cards->get($key)['url'];
            $this->assertIsString($url);
            $this->assertStringContainsString('store_id='.$store->id, $url);
            $this->actingAs($actor)->get($url)->assertOk();
        }
    }

    public function test_local_super_admin_bootstraps_missing_enabled_erp_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-bootstrap@example.test');
        $this->wholesaleStore('P135-READY-LOCAL-BOOTSTRAP');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
    }

    public function test_local_super_admin_does_not_override_explicit_entitlement_deny(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            false,
            null,
            $actor,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => false,
        ]);
    }

    public function test_local_super_admin_bootstraps_platform_entitlement_when_only_narrower_scope_is_configured(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-narrower-deny@example.test');
        $this->wholesaleStore('P135-READY-LOCAL-NARROWER-DENY');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        EnterpriseModuleEntitlement::query()->create([
            'module_code' => 'procurement',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => 999,
            'scope_key' => 'store:999',
            'is_enabled' => false,
            'capabilities' => null,
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:999',
            'is_enabled' => false,
        ]);
    }

    public function test_production_super_admin_remains_deny_by_default_without_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-production-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);
    }

    public function test_enabled_entitled_but_uncertified_erp_module_has_no_operational_link(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-uncertified@example.test');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));
        $response->assertOk();

        $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);
        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'procurement',
            'state' => 'ENABLED',
        ]);
    }

    public function test_owner_reported_uncertified_erp_modules_remain_nonactionable_in_both_locales(): void
    {
        // This reproduces the owner's five degraded ERP cards without
        // manufacturing an operational certificate or relaxing real HTTP.
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'ar', 'p135-local-uncertified@example.test');
        $this->app['env'] = 'production';
        $this->wholesaleStore('P135-UNCERTIFIED-ERP-STORE');

        $modules = [
            'procurement' => ['procurement', 'admin.erp.procurement.index'],
            'wms' => ['inventory_wms', 'admin.erp.wms.index'],
            'finance' => ['finance', 'admin.erp.finance.index'],
            'treasury' => ['treasury', 'admin.erp.treasury.index'],
            'bi' => ['enterprise_bi', 'admin.erp.bi'],
        ];

        foreach ($modules as [$code]) {
            app(EnterpriseModuleAdministration::class)->perform($code, 'enable', $admin);
            app(ModuleEntitlementManager::class)->set(
                $code,
                EntitlementScope::platform(),
                true,
                null,
                $admin,
            );
        }

        // Simulate production defaults, overriding any synthetic evidence
        // installed globally by this test suite's positive-case setup.
        config()->set('enterprise_module_readiness.certified_versions', []);
        config()->set('enterprise_module_readiness.workflow_attestations', []);
        config()->set('enterprise_module_readiness.trusted_public_keys', []);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();
            $response = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.erp_center.denial_reasons.uncertified', [], $locale));

            $cards = collect($response->viewData('cards'))->keyBy('key');
            foreach ($modules as $cardKey => [, $route]) {
                $card = $cards->get($cardKey);
                $this->assertIsArray($card);
                $this->assertSame('degraded', $card['status']);
                $this->assertSame('uncertified', $card['denial_reason']);
                $this->assertNull($card['url']);
                $response->assertSee(
                    'data-erp-key="'.$cardKey.'" data-erp-status="degraded"',
                    false,
                );

                // Direct HTTP cannot bypass a missing signature/version,
                // even for the same authenticated privileged actor.
                $this->actingAs($actor)->get(route($route))->assertForbidden();
            }

            foreach (['customer_service', 'my_work'] as $cardKey) {
                $card = $cards->get($cardKey);
                $this->assertIsArray($card);
                $this->assertSame('available', $card['status']);
                $this->assertIsString($card['url']);
            }
        }

        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
    }

    public function test_uncertified_erp_card_keeps_translated_readiness_explanation_and_accessible_empty_state_without_a_denied_cta(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $this->app['env'] = 'production';
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-erp-readiness-a11y@example.test');
        $store = $this->wholesaleStore('P135-ERP-READINESS-A11Y');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true, null, $admin);

        // Reproduce deployment defaults, never a synthetic certified module.
        config()->set('enterprise_module_readiness.certified_versions', []);
        config()->set('enterprise_module_readiness.trusted_public_keys', []);
        config()->set('enterprise_module_readiness.workflow_attestations', []);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index', [
                'status' => 'degraded',
            ]));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('name="viewport"', false)
                ->assertSee('data-foodex-mobile-web-page="erp-center"', false)
                ->assertSee('@media(max-width:1023px)', false)
                ->assertSee('@media(max-width:767px)', false)
                ->assertSee(':focus-visible', false)
                ->assertSee(__('admin.erp_center.denial_reasons.uncertified', [], $locale));

            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('uncertified', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->assertSame(1, preg_match(
                '/<article class="foodex-card erp-module[^"]*" data-erp-key="procurement".*?<\/article>/s',
                (string) $response->getContent(),
                $matches,
            ));
            $this->assertStringContainsString(
                e(__('admin.erp_center.denial_reasons.uncertified', [], $locale)),
                $matches[0],
            );
            $this->assertStringNotContainsString('foodex-action-primary', $matches[0]);
            $this->assertStringNotContainsString('href=', $matches[0]);

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
            ]))->assertForbidden();

            // Empty/status display is server-rendered and announced with
            // role=status. No client-side loading spinner is invented.
            $empty = $this->actingAs($admin->fresh())->get(route('admin.erp.index', [
                'status' => 'not_entitled',
            ]));
            $empty->assertOk()
                ->assertSee('role="status"', false)
                ->assertSee(__('admin.erp_center.empty_filtered', [], $locale));
            $this->assertSame([], $empty->viewData('visibleCards'));
        }
    }

    public function test_enabled_and_entitled_module_exposes_only_its_canonical_destination(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-enabled@example.test');
        $this->wholesaleStore('P135-READY-ENABLED');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['module' => 'procurement']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertSee(route('admin.erp.procurement.index'), false);

        // P238: ERP Center retains the canonical workbench journey; its
        // operational shortcut now lives in the Procurement business group.
        $procurementGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'procurement');
        $this->assertNotNull($procurementGroup);

        $procurement = collect($procurementGroup['children'])->firstWhere('key', 'erp_procurement');
        $this->assertNotNull($procurement);
        $this->assertSame('admin.erp.procurement.index', $procurement['route']);
        $this->assertSame([], $procurement['params']);
    }

    public function test_erp_navigation_source_uses_canonical_workbench_routes(): void
    {
        $navigation = file_get_contents(app_path('Support/AdminNavigation.php'));
        $center = file_get_contents(app_path('Http/Controllers/Admin/ErpCenterController.php'));

        $this->assertIsString($navigation);
        $this->assertIsString($center);

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.customer-service',
            'admin.erp.bi',
        ] as $route) {
            $this->assertStringContainsString("'".$route."'", $navigation);
            $this->assertStringContainsString("'".$route."'", $center);
        }
    }

    public function test_finance_actor_does_not_receive_procurement_or_wms_actions(): void
    {
        $actor = $this->userWithGlobalRole('FINANCE', 'ar', 'erp-finance@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('مركز ERP')
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('data-erp-key="procurement"', false)
            ->assertDontSee('data-erp-key="wms"', false);
    }

    public function test_erp_center_renders_authoritative_snapshot_drilldowns_and_accessible_analytics(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-snapshot@example.test');
        $this->wholesaleStore('P135-READY-SNAPSHOT');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        // G009: each independent ERP Center panel is rendered only for its selected tab.
        $snapshot = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'snapshot']));
        $snapshot
            ->assertOk()
            ->assertSee('data-erp-kpis', false)
            ->assertSee('data-erp-kpi="pending_approvals"', false)
            ->assertSee('data-erp-kpi="overdue_work"', false)
            ->assertSee('data-erp-kpi="open_exceptions"', false)
            ->assertSee('data-erp-kpi="failed_reports"', false)
            ->assertSee('data-erp-kpi="module_health"', false)
            ->assertDontSee('data-erp-chart="workload"', false);

        $analytics = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'analytics']));
        $analytics
            ->assertOk()
            ->assertSee('data-erp-chart="workload"', false)
            ->assertSee('data-erp-chart="states"', false)
            ->assertSee('data-erp-work-module="procurement"', false)
            ->assertDontSee('data-erp-kpis', false);

        $followUp = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'follow_up']));
        $followUp
            ->assertOk()
            ->assertSee('data-erp-my-work', false)
            ->assertSee('data-erp-activity', false)
            ->assertSee(route('admin.action-center.index'), false)
            ->assertDontSee('data-erp-chart="states"', false);

        $modules = $this->actingAs($actor)->get(route('admin.erp.index', ['tab' => 'modules']));
        $modules
            ->assertOk()
            ->assertSee(route('admin.erp.procurement.index'), false)
            ->assertDontSee('data-erp-my-work', false);
    }

    public function test_failed_report_kpi_is_unavailable_without_bi_and_scope_aware_when_bi_is_operational(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-bi-admin@example.test');
        $this->app['env'] = 'production';

        $unavailable = $this->actingAs($admin)->get(route('admin.erp.index'));
        $unavailableKpi = collect($unavailable->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($unavailableKpi);
        $this->assertFalse($unavailableKpi['available']);
        $this->assertNull($unavailableKpi['value']);
        $this->assertNull($unavailableKpi['url']);

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );

        $actor = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-finance@example.test');
        $other = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-other@example.test');

        foreach ([
            [$actor->id, 'erp-center-own-failure'],
            [$other->id, 'erp-center-other-failure'],
        ] as [$requestedBy, $key]) {
            DB::table('enterprise_report_exports')->insert([
                'report_code' => 'orders.summary',
                'scope_type' => 'platform',
                'scope_id' => null,
                'requested_by' => $requestedBy,
                'scheduled_report_id' => null,
                'query_payload' => '{}',
                'row_count' => 0,
                'execution_mode' => 'queued_chunked',
                'chunk_size' => 500,
                'next_offset' => 0,
                'processed_rows' => 0,
                'chunks_completed' => 0,
                'status' => 'failed',
                'failure_code' => 'source_unavailable',
                'artifact_metadata' => null,
                'idempotency_key' => hash('sha256', $key),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));
        $kpi = collect($response->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($kpi);
        $this->assertTrue($kpi['available']);
        $this->assertSame(1, $kpi['value']);
        $this->assertSame(route('admin.erp.bi'), $kpi['url']);
    }

    public function test_erp_center_state_filter_hides_other_module_cards_without_changing_navigation_authority(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-state-filter@example.test');
        $this->wholesaleStore('P135-READY-STATE-FILTER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['status' => 'available']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertDontSee('data-erp-key="wms" data-erp-status="disabled"', false);

        $erpGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'erp');
        $this->assertNotNull($erpGroup);
        $this->assertNotNull(collect($erpGroup['children'])->firstWhere('key', 'erp_center'));
    }

    public function test_wholesale_procurement_store_entitlement_projects_existing_read_route_without_platform_grant(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-wholesale-setup@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'erp-wholesale-buyer@example.test');
        $store = $this->wholesaleStore('P135-READ-OWN');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
        ]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.procurement.index', ['store_id' => $store->id]), $card['url']);

            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertIsArray($group);
            $item = collect($group['children'])->firstWhere('key', 'erp_procurement');
            $this->assertIsArray($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))->assertOk();
        }
    }

    public function test_wholesale_store_only_procurement_sidebar_chooses_entitled_store_instead_of_first_b2b_store(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-procurement-link-admin@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-procurement-link-buyer@example.test');
        $first = $this->wholesaleStore('P135-ALPHABET-FIRST-NO-ENTITLEMENT');
        $entitled = $this->wholesaleStore('P135-ZULU-SECOND-ENTITLED');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', new EntitlementScope(EntitlementScope::STORE, $entitled->id), true);
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
        ]);
        $this->assertTrue($buyer->hasPermission('procurement.suppliers.view', $first->id));
        $this->assertTrue($buyer->hasPermission('procurement.suppliers.view', $entitled->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertIsArray($group);
            $item = collect($group['children'])->firstWhere('key', 'erp_procurement');
            $this->assertIsArray($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame(['store_id' => (string) $entitled->id], $item['params']);

            $page = $this->actingAs($buyer->fresh())
                ->get(route($item['route'], $item['params']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->assertSame($entitled->id, $page->viewData('selectedStoreId'));

            $center = $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.procurement.index', [
                'store_id' => $entitled->id,
            ]), $card['url']);
        }

        // A user-level store DENY wins over the Wholesale role and the
        // still-present module entitlement; never substitute the first store.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $buyer->id,
            'permission_id' => Permission::query()->where('code', 'procurement.suppliers.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$entitled->id,
            'store_id' => $entitled->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $entitled->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $first->id));

        // Supplier DENY must hide only Suppliers, not the Buyer's separately
        // authorized Requests read route. The single Sidebar destination must
        // resolve to the same actual entitled B2B store in both locales.
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertIsArray($group);
            $item = collect($group['children'])->firstWhere('key', 'erp_procurement');
            $this->assertIsArray($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame(['store_id' => (string) $entitled->id], $item['params']);
            $this->actingAs($buyer->fresh())->get(route($item['route'], $item['params']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('activeTab', 'requests');

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $entitled->id,
                'tab' => 'suppliers',
            ]))->assertForbidden();
        }

        $center = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'))->assertOk();
        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        // A supplier-specific DENY does not revoke the Buyer's separate
        // purchase-request read permission. The Center links to the existing
        // Workbench's first authorized tab; this is not a supplier grant.
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.view', $entitled->id));
        $this->assertSame('available', $card['status']);
        $this->assertSame(route('admin.erp.procurement.index', [
            'store_id' => $entitled->id,
        ]), $card['url']);
        $this->actingAs($buyer->fresh())->get($card['url'])
            ->assertOk()
            ->assertViewHas('activeTab', 'requests');

        // The *denied* Supplier tab must still reject a forged direct URL,
        // while other authorized read workflows remain accessible.
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $entitled->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();

        // Disabling the only store grant cannot create a platform fallback.
        $entitlements->set('procurement', new EntitlementScope(EntitlementScope::STORE, $entitled->id), false);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
            ->firstWhere('key', 'procurement'));
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $entitled->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->assertDatabaseCount('enterprise_suppliers', 0);
    }

    public function test_wholesale_procurement_read_projection_does_not_invent_an_entitlement_or_readiness(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-wholesale-guard-admin@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'erp-wholesale-guard-buyer@example.test');
        $store = $this->wholesaleStore('P135-READ-DENIED');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);

            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('not_entitled', $card['status']);
            $this->assertSame('entitlement_scope', $card['denial_reason']);
            $this->assertNull($card['url']);
            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertNull($group);
        }

        // Even a real scoped entitlement cannot override missing approval of
        // the exact module version: both Center and direct HTTP stay blocked.
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        // New actor avoids reusing an earlier request's navigation memo.
        $anotherBuyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'ar', 'erp-wholesale-no-cert@example.test');
        $response = $this->actingAs($anotherBuyer)->get(route('admin.erp.index'));
        $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertSame('uncertified', $card['denial_reason']);
        $this->assertNull($card['url']);

        $this->actingAs($anotherBuyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();
    }

    public function test_b2b_procurement_persona_http_enforces_channel_scope_and_explicit_store_denies(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-persona-setup@example.test');
        $owned = $this->wholesaleStore('P135-PERSONA-A');
        $other = $this->wholesaleStore('P135-PERSONA-B');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-PERSONA-RETAIL',
            'name' => 'P135 Retail isolation',
            'is_active' => true,
        ]);

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);

        foreach (['PROCUREMENT_BUYER', 'PROCUREMENT_MANAGER', 'PROCUREMENT_APPROVER'] as $roleCode) {
            $actor = $this->userWithGlobalRole($roleCode, 'en', strtolower($roleCode).'@p135.test');
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $this->assertSame('global', $role->scope);
            $this->assertTrue($role->is_active);
            $this->assertNull($role->archived_at);
            $this->assertTrue($actor->hasRole($roleCode, $owned->id));
            $this->assertFalse($actor->hasRole($roleCode, $retail->id));

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $owned->id,
                    'tab' => 'suppliers',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $retail->id,
                    'tab' => 'suppliers',
                ]))->assertForbidden();
            }

            $this->assertSame($roleCode !== 'PROCUREMENT_APPROVER', $actor->fresh()->hasPermission(
                'procurement.suppliers.manage',
                $owned->id,
            ));
            $this->assertSame($roleCode !== 'PROCUREMENT_BUYER', $actor->fresh()->hasPermission(
                'procurement.purchase_requests.approve',
                $owned->id,
            ));
        }

        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-store-deny@example.test');
        foreach ([
            [$owned, 'P135-SUPPLIER-OWNED'],
            [$other, 'P135-SUPPLIER-OTHER'],
        ] as [$store, $code]) {
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => $code,
                'name_en' => $code,
                'name_ar' => 'مورد اختبار النطاق',
                'payment_terms_days' => 30,
            ])->assertRedirect();
        }

        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $permission) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->id,
                'scope_key' => 'store:'.$owned->id,
                'store_id' => $owned->id,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $owned->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $other->id));
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $owned->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $owned->id,
            'scope_type' => 'store',
            'code' => 'P135-FORGED-DENIED',
            'name_en' => 'Denied Supplier',
            'name_ar' => 'مورد محجوب',
        ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'P135-FORGED-DENIED']);

        $page = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $other->id,
            'tab' => 'suppliers',
        ]))->assertOk();
        $codes = $page->viewData('rows')->getCollection()->pluck('code')->all();
        $this->assertContains('P135-SUPPLIER-OTHER', $codes);
        $this->assertNotContains('P135-SUPPLIER-OWNED', $codes);
    }

    public function test_explicit_global_deny_cannot_be_overridden_by_store_allow_or_unrelated_retail_role_on_procurement_http(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-mixed-role-admin@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-mixed-role-buyer@example.test');
        $storeA = $this->wholesaleStore('P135-MIXED-ROLE-B2B-A');
        $storeB = $this->wholesaleStore('P135-MIXED-ROLE-B2B-B');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-MIXED-ROLE-B2C',
            'name' => 'P135 Mixed Role Retail',
            'is_active' => true,
        ]);
        $retailRole = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $this->assertSame('store', $retailRole->scope);
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retail->id,
            'role_id' => $retailRole->id,
        ]);

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);

        $this->assertTrue($buyer->fresh()->hasRole('PROCUREMENT_BUYER', $storeA->id));
        $this->assertFalse($buyer->fresh()->hasRole('PROCUREMENT_BUYER', $retail->id));
        $this->assertTrue($buyer->fresh()->hasRole('RETAIL_FINANCE', $retail->id));
        $this->assertFalse($buyer->fresh()->hasRole('RETAIL_FINANCE', $storeB->id));

        // An explicit global DENY must win even if the same identity also
        // holds a contrary Store B ALLOW and an unrelated Retail store role.
        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $permission) {
            $permissionId = Permission::query()->where('code', $permission)->firstOrFail()->id;
            foreach ([
                ['scope_key' => 'global', 'store_id' => null, 'effect' => 'deny'],
                ['scope_key' => 'store:'.$storeB->id, 'store_id' => $storeB->id, 'effect' => 'allow'],
            ] as $override) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $buyer->id,
                    'permission_id' => $permissionId,
                    ...$override,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $actor = $buyer->fresh();

        foreach ([$storeA, $storeB] as $store) {
            $this->assertFalse($actor->hasPermission('procurement.suppliers.view', $store->id));
            $this->assertFalse($actor->hasPermission('procurement.suppliers.manage', $store->id));
            $this->assertTrue($actor->hasPermission('procurement.purchase_requests.view', $store->id));
        }
        $this->assertNotContains('procurement.suppliers.view', $actor->effectivePermissionCodes($storeB->id));
        $this->assertNotContains('procurement.suppliers.manage', $actor->effectivePermissionCodes($storeB->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $actor = $buyer->fresh();
            foreach ([$storeA, $storeB] as $store) {
                $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'suppliers',
                ]))->assertForbidden();

                // The independent, still-permitted Requests read path must
                // remain usable; a supplier DENY does not hide all Procurement.
                $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            }

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $retail->id,
                'tab' => 'suppliers',
            ]))->assertForbidden();

            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $storeB->id,
                'scope_type' => 'store',
                'code' => 'P135-MIXED-ROLE-UNAUTHORIZED',
                'name_en' => 'Denied mixed-role supplier',
                'name_ar' => 'مورد غير مصرح',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_suppliers', [
            'code' => 'P135-MIXED-ROLE-UNAUTHORIZED',
        ]);
    }

    public function test_archived_global_procurement_role_and_inactive_user_cannot_use_http_despite_saved_assignments(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-archived-setup@example.test');
        $store = $this->wholesaleStore('P135-ARCHIVE-STORE');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);

        $inactive = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'ar', 'p135-inactive-buyer@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertFalse($inactive->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($inactive->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();

        $active = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-archived-buyer@example.test');
        $role = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $this->assertTrue($active->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($active->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertOk();

        $role->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $this->assertFalse($active->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($active->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();
        $this->assertDatabaseHas('role_user', [
            'user_id' => $active->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_ar_ap_and_treasury_officer_global_roles_do_not_bypass_wholesale_http_channel(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-officers-setup@example.test');
        $store = $this->wholesaleStore('P135-OFFICER-STORE');

        foreach (['finance', 'treasury'] as $module) {
            app(EnterpriseModuleAdministration::class)->perform($module, 'enable', $admin);
            app(ModuleEntitlementManager::class)->set($module, EntitlementScope::platform(), true);
        }

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
        ];

        foreach (['AR_OFFICER', 'AP_OFFICER', 'TREASURY_OFFICER'] as $roleCode) {
            $this->assertNotContains($roleCode, config('admin.channels.b2b.global_roles'));
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $this->assertSame('global', $role->scope);
            $this->assertTrue($role->is_active);
            $this->assertNull($role->archived_at);

            $actor = $this->userWithGlobalRole($roleCode, 'en', strtolower($roleCode).'@p135.test');
            $this->assertTrue($actor->hasRole($roleCode));
            $this->assertFalse($actor->hasRole($roleCode, $store->id));
            $this->assertFalse($actor->hasPermission('finance.view', $store->id));
            $this->assertFalse($actor->hasPermission('finance.post', $store->id));
            $this->assertFalse($actor->hasPermission('finance.approve', $store->id));

            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                foreach ([
                    ['admin.erp.finance.index', 'accounts'],
                    ['admin.erp.finance.index', 'payables'],
                    ['admin.erp.finance.index', 'receivables'],
                    ['admin.erp.treasury.index', 'receipts'],
                ] as [$name, $tab]) {
                    $response = $this->actingAs($actor->fresh())->get(route($name, [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]));
                    $this->assertContains($response->status(), [403, 404]);
                }
            }
        }

        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['receipts'], DB::table('enterprise_treasury_receipts')->count());
    }

    public function test_finance_center_link_matches_real_accountant_legal_entity_and_store_http_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-admin@example.test');
        $actor = $this->userWithGlobalRole('ACCOUNTANT', 'en', 'p135-finance-accountant@example.test');
        $store = $this->wholesaleStore('P135-FINANCE-ACCOUNTANT');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve', $store->id));

        $card = collect($this->actingAs($actor)->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'accounts',
        ]))->assertForbidden();

        // Store-only module capability is NOT legal-entity ledger authority.
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $store->id),
            true, ['finance.foundation.read'],
        );
        $card = collect($this->actingAs($actor)->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'accounts',
        ]))->assertForbidden();

        app(ModuleEntitlementManager::class)->set(
            'finance',
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.gl.read'],
        );

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();
            $response = $this->actingAs($user)->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.finance.index', ['store_id' => $store->id]),
                $card['url'],
            );
            $this->actingAs($user)->get($card['url'])
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'accounts',
            ]))->assertOk();
        }

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertSame('uncertified', $card['denial_reason']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
        ]))->assertForbidden();
        config()->set('enterprise_module_readiness.certified_versions.finance', 1);

        // Child STORE deny overrides legal entity entitlement and permission.
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
        );
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
        ]))->assertForbidden();
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_finance_center_links_only_the_wholesale_store_the_real_workbench_can_read(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-b-admin@example.test');
        $actor = $this->userWithGlobalRole('ACCOUNTANT', 'en', 'p135-finance-b-accountant@example.test');
        $denied = $this->wholesaleStore('P135-FINANCE-A-DENY');
        $allowed = $this->wholesaleStore('P135-FINANCE-B-ALLOW');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($allowed->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read'],
        );
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $denied->id),
            false,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();
            $response = $this->actingAs($user)->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.finance.index', ['store_id' => $allowed->id]),
                $card['url'],
            );
            $this->actingAs($user)->get($card['url'])->assertOk();
            // An unselected B2B store uses the canonical HTTP 404 boundary.
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $denied->id,
            ]))->assertNotFound();
        }
    }

    public function test_retail_store_scope_never_creates_an_erp_finance_destination(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-retail-finance-admin@example.test');
        $actor = User::query()->create([
            'name' => 'Retail finance actor',
            'email' => 'p135-retail-finance-actor@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->assertGreaterThan(0, $retailTypeId);
        $retail = Store::query()->create([
            'store_type_id' => $retailTypeId,
            'code' => 'P135-RETAIL-FINANCE-ONLY',
            'name' => 'P135 Retail Finance Only',
            'is_active' => true,
        ]);
        $retailRole = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $this->assertSame('store', $retailRole->scope);
        $actor->storeRoleAssignments()->create([
            'store_id' => $retail->id,
            'role_id' => $retailRole->id,
        ]);
        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $retail->id));

        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        $navigation = app(AdminNavigation::class);
        $this->assertNull($navigation->firstAuthorizedFinanceStoreId($actor->fresh()));
        $this->assertFalse($navigation->hasAnyPermissionInChannel($actor->fresh(), ['finance.view'], 'b2b'));
        $financeItems = collect($navigation->groupsFor($actor->fresh()))
            ->flatMap(fn (array $group): array => $group['children'])
            ->filter(fn (array $item): bool => in_array($item['key'], ['erp_finance', 'erp_receivables', 'erp_payables'], true));
        $this->assertCount(0, $financeItems);
    }

    public function test_accountant_ar_only_card_is_the_same_authorized_legal_store_http_without_gl_or_ap_grants(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-ar-only-admin@example.test');
        $actor = $this->userWithGlobalRole('ACCOUNTANT', 'en', 'p135-finance-ar-only-accountant@example.test');
        $store = $this->wholesaleStore('P135-FINANCE-AR-ONLY');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve', $store->id));

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            'finance',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['finance.ar.read'],
        );
        $entitlements->set('finance', EntitlementScope::platform(), false);

        // Neither ACCOUNTANT nor a STORE-only grant can create a legal-entity
        // ledger. The Center and actual HTTP must both refuse the shortcut.
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
        ]))->assertForbidden();

        // Only the existing AR read capability is granted by the authorized
        // legal entity. No AP/GL or posting capability is implicitly added.
        $entitlements->set(
            'finance',
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            ['finance.ar.read'],
        );

        $beforeAccounts = DB::table('enterprise_finance_accounts')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();

            $center = $this->actingAs($user)->get(route('admin.erp.index'))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'finance');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ]), $card['url']);

            $this->actingAs($user)->get($card['url'])
                ->assertOk()
                ->assertViewHas('tab', 'receivables')
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'receivables',
            ]))->assertOk()->assertViewHas('tab', 'receivables');
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'payables',
            ]))->assertForbidden();
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'journals',
            ]))->assertForbidden();

            $this->actingAs($user)->post(route('admin.erp.finance.accounts.store'), [
                'store_id' => $store->id,
                'code' => 'P135-UNAUTHORIZED-GL',
                'name' => 'Unauthorized Finance account',
                'type' => 'asset',
                'normal_balance' => 'debit',
            ])->assertForbidden();
        }

        $this->assertSame($beforeAccounts, DB::table('enterprise_finance_accounts')->count());
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);

        // Revocation at the child STORE overrides its genuine legal-entity
        // entitlement, regardless of the actor's inherited ACCOUNTANT role.
        $entitlements->set('finance', new EntitlementScope(EntitlementScope::STORE, $store->id), false);
        app()->forgetInstance(AdminNavigation::class);
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'receivables',
        ]))->assertForbidden();
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_global_catalog_ap_ar_officers_without_channel_admission_have_no_finance_shortcut(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-officers-setup@example.test');
        $store = $this->wholesaleStore('P135-FINANCE-AP-AR-STORE');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read', 'finance.ap.read'],
        );

        foreach (['AR_OFFICER' => 'receivables', 'AP_OFFICER' => 'payables'] as $code => $tab) {
            $this->assertNotContains($code, config('admin.channels.b2b.global_roles'));
            $officer = $this->userWithGlobalRole($code, 'en', strtolower($code).'@p135-finance.test');
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $officer->forceFill(['locale' => $locale])->save();
                $user = $officer->fresh();
                $response = $this->actingAs($user)->get(route('admin.erp.index'));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');
                if (is_array($card)) {
                    $this->assertNotSame('available', $card['status']);
                    $this->assertNull($card['url']);
                }
                $this->actingAs($user)->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id, 'tab' => $tab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_finance_missing_scope_has_distinct_localized_reason_and_no_denied_workbench_button(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-missing-scope@example.test');
        $this->app['env'] = 'production';
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        $card = collect($this->actingAs($admin)->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('entitlement_scope', $card['denial_reason']);
        $this->assertNull($card['url']);

        app(ModuleEntitlementManager::class)->set('finance', EntitlementScope::platform(), true, null, $admin);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index', ['tab' => 'modules']));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.erp_center.denial_reasons.missing_scope', [], $locale));
            $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');
            $this->assertIsArray($card);
            $this->assertSame('not_entitled', $card['status']);
            $this->assertSame('missing_scope', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->assertSame(1, preg_match(
                '/<article class="foodex-card erp-module[^"]*" data-erp-key="finance".*?<\/article>/s',
                (string) $response->getContent(),
                $markup,
            ));
            $this->assertStringNotContainsString('foodex-action-primary', $markup[0]);
            $this->assertStringNotContainsString('{"', $markup[0]);
            $this->assertSame(0, preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i', $markup[0]));
        }

        $this->assertDatabaseCount('enterprise_finance_journals', 0);
    }

    public function test_ready_modules_with_grants_but_no_store_scope_are_nonactionable_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-module-store-scope@example.test');
        $this->app['env'] = 'production';

        foreach (['procurement', 'inventory_wms', 'treasury'] as $module) {
            app(EnterpriseModuleAdministration::class)->perform($module, 'enable', $admin);
            app(ModuleEntitlementManager::class)->set($module, EntitlementScope::platform(), true, null, $admin);
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())->get(route('admin.erp.index', ['tab' => 'modules']));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.erp_center.denial_reasons.missing_scope', [], $locale));

            foreach (['procurement', 'wms', 'treasury'] as $key) {
                $card = collect($response->viewData('cards'))->firstWhere('key', $key);
                $this->assertIsArray($card);
                $this->assertSame('not_entitled', $card['status']);
                $this->assertSame('missing_scope', $card['denial_reason']);
                $this->assertNull($card['url']);
                $this->assertSame(1, preg_match(
                    '/<article class="foodex-card erp-module[^"]*" data-erp-key="'.$key.'".*?<\/article>/s',
                    (string) $response->getContent(),
                    $markup,
                ));
                $this->assertStringNotContainsString('foodex-action-primary', $markup[0]);
            }
        }

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.wms.index',
            'admin.erp.treasury.index',
        ] as $routeName) {
            $this->actingAs($admin->fresh())->get(route($routeName))->assertForbidden();
        }
    }

    public function test_treasury_default_card_requires_receipts_read_at_its_exact_store_and_denies_unprivileged_users(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-treasury-card-setup@example.test');
        $manager = $this->userWithGlobalRole('TREASURY_MANAGER', 'en', 'p135-treasury-card-manager@example.test');
        $storeA = $this->wholesaleStore('P135-TREAS-CARD-A');
        $storeB = $this->wholesaleStore('P135-TREAS-CARD-B');

        app(EnterpriseModuleAdministration::class)->perform('treasury', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeA->id), true, [
            'treasury.receipts.read',
        ]);
        $entitlements->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeB->id), true, [
            'treasury.payments.read',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $response = $this->actingAs($manager->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'treasury');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.treasury.index', ['store_id' => $storeA->id]),
                $card['url'],
            );
            // The actionable card URL must reach Treasury's real default
            // Overview endpoint with the identical actor and store.
            $this->actingAs($manager->fresh())->get($card['url'])->assertOk();
            $this->actingAs($manager->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeB->id,
                'tab' => 'overview',
            ]))->assertForbidden();

            // Payment-only access is distinct from the Overview capability.
            $this->actingAs($manager->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeB->id,
                'tab' => 'payments',
            ]))->assertOk();
        }

        // A payments-only store already admits the real Treasury Payments
        // HTTP read route. The Center must link to that exact same tab, not
        // incorrectly label the whole module "not entitled". It must never
        // grant access to Overview (which requires receipts.read).
        $entitlements->set('treasury', new EntitlementScope(EntitlementScope::STORE, $storeA->id), true, [
            'treasury.payments.read',
        ]);
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $response = $this->actingAs($manager->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'treasury');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.treasury.index', [
                    'store_id' => $storeA->id,
                    'tab' => 'payments',
                ]),
                $card['url'],
            );
            $this->actingAs($manager->fresh())->get($card['url'])
                ->assertOk()
                ->assertViewHas('activeTab', 'payments');
            $this->actingAs($manager->fresh())->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'overview',
            ]))->assertForbidden();
        }

        // A module grant without ANY read capability is not permission to
        // open a Treasury workspace, even if finance.view is present.
        foreach ([$storeA, $storeB] as $store) {
            $entitlements->set(
                'treasury',
                new EntitlementScope(EntitlementScope::STORE, $store->id),
                true,
                ['treasury.payments.manage'],
            );
        }
        app()->forgetInstance(AdminNavigation::class);
        $response = $this->actingAs($manager->fresh())->get(route('admin.erp.index'))->assertOk();
        $card = collect($response->viewData('cards'))->firstWhere('key', 'treasury');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertSame('entitlement_scope', $card['denial_reason']);
        $this->assertNull($card['url']);
        $this->actingAs($manager->fresh())->get(route('admin.erp.treasury.index', [
            'store_id' => $storeA->id,
            'tab' => 'payments',
        ]))->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get(route('admin.erp.treasury.index', [
            'store_id' => $storeA->id,
        ]))->assertForbidden();

        Role::query()->where('code', 'TREASURY_MANAGER')->firstOrFail()
            ->forceFill(['is_active' => false])->save();
        app()->forgetInstance(AdminNavigation::class);
        $this->actingAs($manager->fresh())->get(route('admin.erp.treasury.index', [
            'store_id' => $storeA->id,
        ]))->assertForbidden();

        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_treasury_unhealthy_probe_removes_receipt_card_and_denies_the_same_http_store_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-treasury-health-admin@example.test');
        $manager = $this->userWithGlobalRole('TREASURY_MANAGER', 'en', 'p135-treasury-health-manager@example.test');
        $store = $this->wholesaleStore('P135-TREASURY-HEALTH');

        app(EnterpriseModuleAdministration::class)->perform('treasury', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'treasury', new EntitlementScope(EntitlementScope::STORE, $store->id),
            true, ['treasury.receipts.read'],
        );

        $this->assertTrue($manager->fresh()->hasPermission('finance.view', $store->id));
        // Install the failed probe before the first request in this test.
        // ModuleHealthService memoizes a report for the current request object;
        // a mid-test config change after a healthy GET would reuse stale health.
        // Successful authorized GETs are independently exercised in the role tests.
        config()->set('enterprise_module_health.probes.treasury', [ErpCenterOpaqueFailureProbe::class]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $actor = $manager->fresh();
            $center = $this->actingAs($actor)->get(route('admin.erp.index'))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'treasury');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->actingAs($actor)->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id, 'tab' => 'receipts',
            ]))->assertForbidden();
            $this->assertStringNotContainsString('ERP_PRIVATE_PROBE_MARKER', $center->getContent());
        }
        $this->assertDatabaseCount('enterprise_treasury_receipts', 0);
        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_enterprise_bi_unhealthy_probe_hides_existing_report_card_and_denies_direct_http(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-bi-health-admin@example.test');
        $reporter = $this->userWithGlobalRole('FINANCE', 'en', 'p135-bi-health-reporter@example.test');

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi', EntitlementScope::platform(), true, null, $admin,
        );

        // Install the failed probe before the first request in this test.
        // ModuleHealthService memoizes a report for the current request object;
        // a mid-test config change after a healthy GET would reuse stale health.
        // Successful authorized GETs are independently exercised in the role tests.
        config()->set('enterprise_module_health.probes.enterprise_bi', [ErpCenterOpaqueFailureProbe::class]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $reporter->forceFill(['locale' => $locale])->save();
            $actor = $reporter->fresh();
            $center = $this->actingAs($actor)->get(route('admin.erp.index'))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'bi');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();
            $this->assertStringNotContainsString('ERP_PRIVATE_PROBE_MARKER', $center->getContent());
        }
        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
    }

    public function test_inactive_enterprise_bi_reporting_role_cannot_open_direct_http_despite_platform_entitlement(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-bi-inactive-admin@example.test');
        $reporter = $this->userWithGlobalRole('FINANCE', 'en', 'p135-bi-inactive-reporter@example.test');

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi', EntitlementScope::platform(), true, null, $admin,
        );
        $this->actingAs($reporter->fresh())->get(route('admin.erp.bi'))->assertOk();

        Role::query()->where('code', 'FINANCE')->firstOrFail()
            ->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $reporter->forceFill(['locale' => $locale])->save();
            $actor = $reporter->fresh();
            $this->assertFalse($actor->hasPermission('reports.view'));
            $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.erp.index'))->assertForbidden();
        }
        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
    }

    private function wholesaleStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertGreaterThan(0, $typeId);

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithGlobalRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}

/**
 * Test-only failed health probe: internal messages/JSON/UUID must never be
 * projected into the ERP Center's user-facing denial explanation.
 */
final class ErpCenterOpaqueFailureProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error(
            'p135-opaque-internal-check',
            'ERP_PRIVATE_PROBE_MARKER',
            [
                'opaque_uuid' => '123e4567-e89b-42d3-a456-426614174000',
                'opaque_json' => '{"internal":"not-for-user"}',
            ],
        );
    }
}
