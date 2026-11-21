<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Health\DatabaseHealthProbe;
use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: the ERP Center card must link to the exact same authorized HTTP
 * procurement workbench that enforces each tab, tenant and permission.
 *
 * The signed evidence here is ephemeral and test-only. Production certification
 * is empty until independent G0-G13 source/CI and installed-version acceptance.
 */
final class V2ProcurementErpCenterHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        SyntheticErpWorkflowEvidence::install();
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_ready_card_url_is_real_and_supplier_request_order_tabs_obey_each_role_in_ar_and_en(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-ALLOW');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');
        $this->deny($buyer, ['procurement.purchase_orders.view']);

        $this->assertSame('ready', $this->readiness());
        $this->assertTrue($manager->fresh()->hasPermission('procurement.purchase_orders.view', $store->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.purchase_orders.view', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);

            foreach ([$manager, $buyer] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();

                // The operational cards live in the Modules tab; the default Snapshot tab intentionally does not render them.
                $center = $this->actingAs($user)->get(route('admin.erp.index', ['tab' => 'modules']));
                $center->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');

                $this->assertIsArray($card);
                $this->assertSame('available', $card['status']);
                $this->assertSame(
                    route('admin.erp.procurement.index', ['store_id' => $store->id]),
                    $card['url'],
                );
                $center->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

                // Follow the actual Server-produced card URL; it is not an
                // invented front-end link to a route the actor cannot open.
                $landing = $this->actingAs($user)->get($card['url']);
                $landing->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($store->id, $landing->viewData('selectedStoreId'));

                foreach ([
                    'suppliers' => 'procurement.suppliers.view',
                    'requests' => 'procurement.purchase_requests.view',
                    'orders' => 'procurement.purchase_orders.view',
                ] as $tab => $permission) {
                    $allowed = $user->hasPermission($permission, $store->id);
                    $url = route('admin.erp.procurement.index', [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]);
                    $response = $this->actingAs($user)->get($url);
                    if ($allowed) {
                        $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                        $this->assertSame($tab, $response->viewData('activeTab'));
                        $this->assertContains($tab, collect($landing->viewData('tabs'))->pluck('key')->all());
                    } else {
                        $response->assertForbidden();
                        $this->assertNotContains($tab, collect($landing->viewData('tabs'))->pluck('key')->all());
                    }
                }
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function deniedStates(): array
    {
        return [
            'uncertified' => ['uncertified'],
            'wrong exact version' => ['wrong_version'],
            'missing signed proof' => ['unsigned'],
            'disabled lifecycle' => ['disabled'],
            'suspended lifecycle' => ['suspended'],
            'revoked store entitlement' => ['revoked_entitlement'],
            'revoked all entry permissions' => ['revoked_permissions'],
        ];
    }

    #[DataProvider('deniedStates')]
    public function test_negative_card_has_no_action_and_direct_tabs_remain_denied(string $reason): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-DENY');
        $admin = $this->enableAndEntitle($store);
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');

        $this->assertSame('ready', $this->readiness());
        match ($reason) {
            'uncertified' => config()->set('enterprise_module_readiness.certified_versions.procurement', null),
            'wrong_version' => config()->set(
                'enterprise_module_readiness.certified_versions.procurement',
                app(ModuleRegistry::class)->manifest('procurement')->version + 1,
            ),
            'unsigned' => config()->set('enterprise_module_readiness.workflow_attestations.procurement', null),
            'disabled', 'suspended' => app(EnterpriseModuleAdministration::class)
                ->perform('procurement', $reason === 'disabled' ? 'disable' : 'suspend', $admin),
            'revoked_entitlement' => app(ModuleEntitlementManager::class)->set(
                'procurement', new EntitlementScope(EntitlementScope::STORE, $store->id), false,
            ),
            'revoked_permissions' => $this->deny($buyer, [
                'procurement.suppliers.view',
                'procurement.purchase_requests.view',
                'procurement.purchase_orders.view',
            ]),
        };

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $user = $buyer->fresh();

            // A revoked role may also lose access to the ERP Center entirely;
            // neither case may expose an actionable Procurement link.
            $center = $this->actingAs($user)->get(route('admin.erp.index'));
            if ($center->status() === 200) {
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
                if (is_array($card)) {
                    $this->assertNotSame('available', $card['status'], $reason.'/'.$locale);
                    $this->assertNull($card['url']);
                } else {
                    // A completely omitted module card is stricter than a
                    // displayed unavailable card; neither may leak a CTA.
                    $this->assertNull($card);
                    $center->assertDontSee(
                        'data-erp-key="procurement" data-erp-status="available"',
                        false,
                    );
                }
            } else {
                $center->assertForbidden();
            }

            foreach (['suppliers', 'requests', 'orders'] as $tab) {
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_foreign_store_and_retail_to_wholesale_forgery_cannot_follow_available_card(): void
    {
        $home = $this->wholesaleStore('P135-HTTP-CARD-HOME');
        $foreign = $this->wholesaleStore('P135-HTTP-CARD-FOREIGN');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-HTTP-CARD-RETAIL',
            'name' => 'P135 HTTP retail channel',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $this->enableAndEntitle($home);
        $buyer = $this->userWithRole('PROCUREMENT_BUYER');
        $retailActor = $this->userWithRole('B2C_STORE_ADMIN', $retail);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $retailActor->forceFill(['locale' => $locale])->save();

            $center = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $center->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.procurement.index', ['store_id' => $home->id]), $card['url']);
            $this->actingAs($buyer->fresh())->get($card['url'])->assertOk();

            foreach (['suppliers', 'requests', 'orders'] as $tab) {
                foreach ([$foreign, $retail] as $deniedStore) {
                    $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                        'store_id' => $deniedStore->id,
                        'tab' => $tab,
                    ]))->assertForbidden();
                }
                $this->actingAs($retailActor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $home->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }
            $this->actingAs($retailActor->fresh())->get(route('admin.erp.index'))->assertForbidden();
        }
    }

    public function test_explicit_super_admin_store_and_global_denies_never_advertise_procurement_workbench(): void
    {
        $deniedStore = $this->wholesaleStore('P135-SUPER-DENY');
        $admin = $this->enableAndEntitle($deniedStore);
        $viewPermissions = [
            'procurement.suppliers.view',
            'procurement.purchase_requests.view',
            'procurement.purchase_orders.view',
        ];

        // Super Admin hasPermission() is intentionally privileged; the
        // ERP Center must still respect explicit per-user STORE denial.
        foreach ($viewPermissions as $permissionCode) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $admin->id,
                'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
                'scope_key' => 'store:'.$deniedStore->id,
                'store_id' => $deniedStore->id,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $admin->forceFill(['has_permission_overrides' => true])->save();
        $this->assertTrue($admin->fresh()->hasPermission('procurement.suppliers.view', $deniedStore->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($admin->fresh())->get(route('admin.erp.index'))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertNotSame('available', $card['status']);
            $this->assertNull($card['url']);
        }

        // An independently permitted and entitled Wholesale store is a valid
        // alternative. The denied store must never become the selected CTA.
        $allowedStore = $this->wholesaleStore('P135-SUPER-ALLOW');
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $allowedStore->id),
            true,
        );

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($admin->fresh())->get(route('admin.erp.index'))->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.procurement.index', ['store_id' => $allowedStore->id]),
                $card['url'],
            );
        }

        // GLOBAL DENY must take precedence even when another B2B store exists
        // and PLATFORM-level module entitlement is present.
        $globallyDenied = $this->userWithRole('SUPER_ADMIN');
        $this->deny($globallyDenied, $viewPermissions);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $globallyDenied->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($globallyDenied->fresh())->get(route('admin.erp.index'))->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertNotSame('available', $card['status']);
            $this->assertNull($card['url']);
        }
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
    }

    public function test_archived_role_and_inactive_actor_revoke_both_center_and_workbench_http(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CARD-ARCHIVED');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $inactive = $this->userWithRole('PROCUREMENT_BUYER');
        $this->actingAs($manager->fresh())->get(route('admin.erp.index'))->assertOk();
        $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id, 'tab' => 'suppliers',
        ]))->assertOk();

        Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()
            ->forceFill(['archived_at' => now()])->save();
        $inactive->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            foreach ([$manager, $inactive] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.index'))->assertForbidden();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id, 'tab' => 'suppliers',
                ]))->assertForbidden();
            }
        }
    }

    public function test_revoked_certification_stays_unavailable_after_suspend_and_reenable_until_valid_test_evidence_is_restored(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-REVOKE-REENABLE');
        $admin = $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        $module = app(EnterpriseModuleAdministration::class);
        $workbenchUrl = route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]);

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($workbenchUrl)->assertOk();

        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        $this->assertSame('uncertified', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        }

        $module->perform('procurement', 'suspend', $admin);
        $this->assertSame('inactive', $this->readiness());
        $this->actingAs($manager->fresh())->get($workbenchUrl)->assertForbidden();

        $module->perform('procurement', 'enable', $admin);
        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'procurement',
            'state' => 'ENABLED',
        ]);

        // ENABLED and preserved role + store entitlement must never recreate
        // revoked exact-version certification or silently reauthorize HTTP.
        $this->assertSame('uncertified', $this->readiness());
        $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:'.$store->id,
            'is_enabled' => true,
        ]);

        // This restoration is strictly the disposable PHPUnit signature
        // fixture installed in setUp, NEVER a live G0-G13 certification.
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);
        $this->assertSame('ready', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($manager->fresh())->get(route('admin.erp.index'))->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertIsString($card['url']);
            $this->actingAs($manager->fresh())->get($workbenchUrl)->assertOk();
        }
    }

    public function test_missing_workflow_config_forged_signature_and_stale_installed_manifest_never_expose_actionable_http(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-CONFIG-SIGNATURE');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $registry = app(ModuleRegistry::class);
        $version = $registry->manifest('procurement')->version;
        $registration = $registry->requireRegistration('procurement');
        $originalManifest = $registration->getAttribute('manifest');
        $requiredWorkflow = config('enterprise_module_readiness.required_workflows.procurement');
        $signedFixture = config('enterprise_module_readiness.workflow_attestations.procurement');
        $workbenchUrl = route('admin.erp.procurement.index', [
            'store_id' => $store->id, 'tab' => 'suppliers',
        ]);

        $this->assertIsArray($originalManifest);
        $this->assertIsArray($signedFixture);
        $this->assertSame('ready', $this->readiness());

        // A missing mandatory workflow matrix is not an optional module.
        config()->set('enterprise_module_readiness.required_workflows.procurement', []);
        $this->assertSame('uncertified', $this->readiness());
        $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        config()->set('enterprise_module_readiness.required_workflows.procurement', $requiredWorkflow);

        // An apparently plausible new Git SHA invalidates the Ed25519 proof.
        config()->set('enterprise_module_readiness.workflow_attestations.procurement', [
            ...$signedFixture,
            'head_sha' => str_repeat('f', 40),
        ]);
        $this->assertSame('uncertified', $this->readiness());
        $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        config()->set('enterprise_module_readiness.workflow_attestations.procurement', $signedFixture);

        // Source certification of version N cannot authorize an installed N+1
        // even when the stored lifecycle stays ENABLED.
        $registration->forceFill(['manifest_version' => $version + 1])->save();
        $this->assertSame('uncertified', $this->readiness());
        $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        $registration->forceFill(['manifest_version' => $version])->save();

        // Same version, altered permissions: SHA-256 installed manifest mismatch.
        $registration->forceFill([
            'manifest' => [...$originalManifest, 'permissions' => ['forged.procurement.post']],
        ])->save();
        $this->assertSame('uncertified', $this->readiness());
        $this->assertProcurementHttpUnavailable($manager->fresh(), $workbenchUrl);
        $registration->forceFill(['manifest' => $originalManifest])->save();

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($workbenchUrl)->assertOk();
    }

    public function test_degraded_operational_health_blocks_even_exact_version_signed_test_evidence_without_leaking_probe_details(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-DEGRADED-HEALTH');
        $admin = $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');

        // Set the failed probe before the first health report; no stale
        // memoized HEALTHY result from an earlier simulated HTTP request.
        config()->set('enterprise_module_health.probes.procurement', [
            P135ProcurementDegradedHealthProbe::class,
        ]);
        app()->forgetInstance(ModuleHealthService::class);

        $this->assertSame('unhealthy', $this->readiness());
        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'procurement', 'state' => 'ENABLED',
        ]);
        $this->assertTrue($manager->fresh()->hasPermission('procurement.suppliers.view', $store->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $actor = $manager->fresh();
            $auditBefore = DB::table('audit_logs')->count();
            $center = $this->actingAs($actor)->get(route('admin.erp.index'));
            $center->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertSame('degraded', $card['health']);
            $this->assertNull($card['url']);
            $center->assertDontSee('PRIVATE-P135-PROBE-DETAIL');

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id, 'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }

        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertDatabaseCount('enterprise_purchase_invoices', 0);
        $this->assertDatabaseCount('enterprise_outbox_messages', 0);

        // Lifecycle and signed test configuration were not changed by a
        // read-only degraded health probe.
        $this->assertSame('unhealthy', $this->readiness());
        $this->assertTrue($admin->fresh()->hasRole('SUPER_ADMIN'));
    }

    public function test_missing_manifest_required_database_probe_degrades_card_and_blocks_http_even_when_signed(): void
    {
        $store = $this->wholesaleStore('P135-HTTP-MISSING-HEALTH-PROBE');
        $this->enableAndEntitle($store);
        $manager = $this->userWithRole('PROCUREMENT_MANAGER');
        $url = route('admin.erp.procurement.index', [
            'store_id' => $store->id, 'tab' => 'suppliers',
        ]);
        $configured = config('enterprise_module_health.probes.procurement');

        $this->assertIsArray($configured);
        $this->assertContains(DatabaseHealthProbe::class, $configured);
        $this->assertContains('database', app(ModuleRegistry::class)->manifest('procurement')->healthChecks);
        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($url)->assertOk();

        // The exact signed manifest continues to require 'database'. A
        // misconfigured empty probe list must NOT count as HEALTHY/readiness.
        config()->set('enterprise_module_health.probes.procurement', []);
        app()->forgetInstance(ModuleHealthService::class);
        app()->forgetInstance(ModuleOperationalReadiness::class);
        $this->assertSame('unhealthy', $this->readiness());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $actor = $manager->fresh();
            $auditBefore = DB::table('audit_logs')->count();
            $center = $this->actingAs($actor)->get(route('admin.erp.index'));
            $center->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->actingAs($actor)->get($url)->assertForbidden();
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }

        // Only the disposable PHPUnit config is repaired. The production
        // version certification/trust maps are never populated by this test.
        config()->set('enterprise_module_health.probes.procurement', $configured);
        app()->forgetInstance(ModuleHealthService::class);
        app()->forgetInstance(ModuleOperationalReadiness::class);
        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($url)->assertOk();
    }

    private function assertProcurementHttpUnavailable(User $actor, string $workbenchUrl): void
    {
        $auditBefore = DB::table('audit_logs')->count();
        $center = $this->actingAs($actor)->get(route('admin.erp.index'))->assertOk();
        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');

        // The Center may entirely omit the module for this actor; either
        // presentation is secure only if no actionable URL survives.
        if (is_array($card)) {
            $this->assertNotSame('available', $card['status']);
            $this->assertNull($card['url']);
        } else {
            $this->assertNull($card);
            $center->assertDontSee(
                'data-erp-key="procurement" data-erp-status="available"',
                false,
            );
        }

        $this->actingAs($actor)->get($workbenchUrl)->assertForbidden();
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    private function enableAndEntitle(Store $store): User
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );

        return $admin;
    }

    private function readiness(): string
    {
        $registry = app(ModuleRegistry::class);

        return app(ModuleOperationalReadiness::class)->status(
            'procurement',
            $registry->manifest('procurement')->version,
            $registry->state('procurement'),
        );
    }

    private function wholesaleStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithRole(string $roleCode, ?Store $store = null): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        if ($store === null) {
            $actor->roles()->attach($role);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'role_id' => $role->id,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    /** @param list<string> $codes */
    private function deny(User $actor, array $codes): void
    {
        foreach ($codes as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}

/**
 * Disposable negative probe; it never runs in live app configuration.
 * Its internal message must not escape into an ERP Center denial page.
 */
final class P135ProcurementDegradedHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::degraded('procurement-runtime', 'PRIVATE-P135-PROBE-DETAIL');
    }
}
