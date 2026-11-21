<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: eleven tabs in ONE canonical Procurement HTTP route.
 * Readiness simulation is test-only; production has no certified version.
 */
final class V2ErpProcurementTabRouteMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const TAB_READ_PERMISSIONS = [
        'suppliers' => 'procurement.suppliers.view',
        'requests' => 'procurement.purchase_requests.view',
        'rfqs' => 'procurement.purchase_requests.view',
        'orders' => 'procurement.purchase_orders.view',
        'contracts' => 'procurement.purchase_orders.view',
        'receipts' => 'procurement.goods_receipts.view',
        'invoices' => 'procurement.purchase_invoices.view',
        'matches' => 'procurement.purchase_invoices.view',
        'returns' => 'procurement.purchase_returns.view',
        'statements' => 'procurement.supplier_statements.view',
        'reports' => 'procurement.supplier_statements.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Synthetic Ed25519 evidence and exact version live inside PHPUnit.
        // Neither production keys nor any live certification is generated.
        SyntheticErpWorkflowEvidence::install();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }

    public function test_all_eleven_existing_tabs_allow_direct_authorized_ready_http_in_ar_and_en(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();

        $this->assertSame('ready', $this->readiness());
        $this->assertCount(11, self::TAB_READ_PERMISSIONS);
        $this->assertSame('global', Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->scope);

        // There is exactly one canonical Procurement navigation destination,
        // not one new Sidebar item per tab. HTTP remains the authority.
        $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
        $procurement = collect($groups)->firstWhere('key', 'procurement');
        $this->assertIsArray($procurement);
        $links = collect($procurement['children'])
            ->filter(static fn (array $item): bool => ($item['route'] ?? null) === 'admin.erp.procurement.index');
        $this->assertCount(1, $links);
        $this->assertSame('erp_procurement', $links->first()['key']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue(
                    $actor->fresh()->hasPermission($permission, $store->id),
                    "{$locale}/{$tab}: missing explicit HTTP read permission",
                );

                $response = $this->actingAs($actor->fresh())->get($this->tabUrl($tab, $store));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($tab, $response->viewData('activeTab'), "{$locale}/{$tab}: wrong active tab");
                $this->assertSame($store->id, $response->viewData('selectedStoreId'));
                $this->assertContains(
                    $tab,
                    collect($response->viewData('tabs'))->pluck('key')->all(),
                    "{$locale}/{$tab}: tab not projected for permitted store",
                );
            }
        }
    }

    public function test_one_authorized_sidebar_destination_renders_keyboard_focusable_localized_workbench_tabs_and_feedback(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();

        $this->assertSame('ready', $this->readiness());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            // A business-domain Sidebar has ONE canonical Procurement entry,
            // never a fabricated entry for each Workbench tab.
            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $procurement = collect($groups)->firstWhere('key', 'procurement');
            $this->assertIsArray($procurement);
            $links = collect($procurement['children'])
                ->filter(static fn (array $item): bool => ($item['route'] ?? '') === 'admin.erp.procurement.index');
            $this->assertCount(1, $links);
            $this->assertSame('erp_procurement', $links->first()['key']);
            $this->actingAs($actor->fresh())
                ->get(route($links->first()['route'], $links->first()['params'] ?? []))
                ->assertOk();

            $errors = new ViewErrorBag;
            $errors->put('default', new MessageBag(['source' => ['P135_WORKBENCH_ERROR']]));
            $response = $this->actingAs($actor->fresh())
                ->withSession(['status' => 'P135_WORKBENCH_SAVED', 'errors' => $errors])
                ->get($this->tabUrl('requests', $store));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('name="viewport"', false)
                ->assertSee('data-erp-procurement-workbench="true"', false)
                ->assertSee(__('enterprise_procurement.title', [], $locale))
                ->assertSee(__('enterprise_procurement.description', [], $locale))
                ->assertSee(__('enterprise_procurement.empty', [], $locale))
                ->assertSee('role="status"', false)
                ->assertSee('P135_WORKBENCH_SAVED')
                ->assertSee('role="alert"', false)
                ->assertSee('P135_WORKBENCH_ERROR')
                ->assertSee('@media(max-width:767px)', false)
                ->assertSee('.erp-table-wrap{overflow:auto}', false)
                ->assertSee(':focus-visible', false)
                ->assertSee('<noscript>', false);

            $this->assertSame('requests', $response->viewData('activeTab'));
            $this->assertSame($store->id, $response->viewData('selectedStoreId'));

            $markup = (string) $response->getContent();
            $this->assertSame(1, preg_match(
                '/<nav class="foodex-tabs(?: [^"]+)?"[^>]*>(.*?)<\/nav>/s',
                $markup,
                $matches,
            ));
            $rail = $matches[1];
            $this->assertStringContainsString(
                'aria-label="'.e(__('enterprise_procurement.title', [], $locale)).'"',
                $markup,
            );
            $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
            $this->assertStringNotContainsString('tabindex="-1"', $rail);
            $this->assertStringNotContainsString('role="tab"', $rail);
            $this->assertStringNotContainsString('<button', $rail);
            $this->assertStringNotContainsString('javascript:', $rail);

            // Active keyboard-reachable link must be the actual authorized
            // Requests route, not an inert visual tab or a fabricated URL.
            $this->assertSame(1, preg_match_all(
                '/<a\\b[^>]*aria-current="page"[^>]*>.*?<\\/a>/s',
                $rail,
                $activeLinks,
            ));
            $this->assertStringContainsString(
                'href="'.e(route('admin.erp.procurement.index', [
                    'tab' => 'requests',
                    'store_id' => $store->id,
                ])).'"',
                $activeLinks[0][0],
            );
            $this->assertStringContainsString(
                e(__('enterprise_procurement.tabs.requests', [], $locale)),
                $activeLinks[0][0],
            );

            $tabs = $response->viewData('tabs');
            $this->assertIsArray($tabs);
            $this->assertGreaterThanOrEqual(count(self::TAB_READ_PERMISSIONS), count($tabs));
            $this->assertSame(count($tabs), preg_match_all('/<a\b[^>]*href="[^"]+"[^>]*>.*?<\/a>/s', $rail));

            // Native href anchors are keyboard reachable without JS, and
            // each tab targets only its already HTTP-authorized workspace.
            foreach ($tabs as $tab) {
                $this->assertStringContainsString(
                    'href="'.e(route('admin.erp.procurement.index', [
                        'tab' => $tab['key'],
                        'store_id' => $store->id,
                    ])).'"',
                    $rail,
                );
                $this->assertStringContainsString(e($tab['label']), $rail);
            }
        }

        // Revoked certification removes the Sidebar destination AND causes
        // the identical direct HTTP tab route to deny, not just be hidden.
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        app()->forgetInstance(AdminNavigation::class);
        $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
        $procurement = collect($groups)->firstWhere('key', 'procurement');
        if (is_array($procurement)) {
            $this->assertNull(collect($procurement['children'])->firstWhere('key', 'erp_procurement'));
        }
        $this->actingAs($actor->fresh())
            ->get($this->tabUrl('requests', $store))
            ->assertForbidden();
    }

    public function test_mixed_permissions_project_only_real_http_tabs_inside_one_localized_accessible_workbench(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();
        $this->assertSame('ready', $this->readiness());

        // The existing manager role contains all eleven read grants. Deny
        // every family except Requests (also the RFQ tab), without changing
        // lifecycle, store assignment, signed test readiness or entitlements.
        $deniedPermissions = array_values(array_filter(
            array_unique(array_values(self::TAB_READ_PERMISSIONS)),
            static fn (string $permission): bool => $permission !== 'procurement.purchase_requests.view',
        ));
        $this->denyPermissions($actor, $deniedPermissions, null);
        $expectedTabs = ['requests', 'rfqs'];
        $forbiddenTabs = array_values(array_diff(array_keys(self::TAB_READ_PERMISSIONS), $expectedTabs));
        $beforeAudit = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();
            app()->forgetInstance(AdminNavigation::class);

            $this->assertFalse($user->hasPermission('procurement.suppliers.view', $store->id));
            $this->assertTrue($user->hasPermission('procurement.purchase_requests.view', $store->id));

            $groups = collect(app(AdminNavigation::class)->groupsFor($user));
            $procurement = $groups->firstWhere('key', 'procurement');
            $this->assertIsArray($procurement);
            $items = collect($procurement['children']);
            $this->assertCount(1, $items);
            $item = $items->firstWhere('key', 'erp_procurement');
            $this->assertIsArray($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame(['store_id' => (string) $store->id], $item['params']);

            // A Sidebar link must resolve to authorized HTTP, not a page
            // that is visually present but forbidden to the same principal.
            $this->actingAs($user)
                ->get(route($item['route'], $item['params']))
                ->assertOk();

            $response = $this->actingAs($user)
                ->get($this->tabUrl('requests', $store));
            $response->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('name="viewport"', false)
                ->assertSee('data-erp-procurement-workbench="true"', false)
                ->assertSee('@media(max-width:767px)', false)
                ->assertSee('.erp-table-wrap{overflow:auto}', false)
                ->assertSee(':focus-visible', false)
                ->assertSee('<noscript>', false);
            $this->assertSame($expectedTabs, collect($response->viewData('tabs'))->pluck('key')->all());

            $markup = (string) $response->getContent();
            $this->assertSame(1, preg_match(
                '/<nav class="foodex-tabs(?: [^"]+)?"[^>]*>(.*?)<\/nav>/s',
                $markup,
                $matches,
            ));
            $rail = $matches[1];
            $this->assertStringContainsString(
                'aria-label="'.e(__('enterprise_procurement.title', [], $locale)).'"',
                $markup,
            );
            $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
            // The browser does not assign authority by query parameter ordering.
            // Parse every actual anchor: an exact authorized path/store/tab set
            // forbids hidden or cross-store links while allowing either URL order.
            $this->assertSame(
                count($expectedTabs),
                preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>.*?<\/a>/s', $rail, $anchors),
            );
            $actualDestinations = [];
            foreach ($anchors[1] as $escapedHref) {
                $actualHref = html_entity_decode($escapedHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                parse_str((string) parse_url($actualHref, PHP_URL_QUERY), $query);
                $actualDestinations[] = [
                    'path' => parse_url($actualHref, PHP_URL_PATH),
                    'store_id' => $query['store_id'] ?? null,
                    'tab' => $query['tab'] ?? null,
                ];
            }
            $this->assertSame(
                array_map(static fn (string $tab): array => [
                    'path' => parse_url(route('admin.erp.procurement.index'), PHP_URL_PATH),
                    'store_id' => (string) $store->id,
                    'tab' => $tab,
                ], $expectedTabs),
                $actualDestinations,
            );
            $this->assertStringNotContainsString('tabindex="-1"', $rail);

            foreach ($expectedTabs as $tab) {
                $href = route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]);
                // Canonical path, store and tab verified from actual anchor href above.
                $translatedLabel = __('enterprise_procurement.tabs.'.$tab, [], $locale);
                $this->assertNotSame('enterprise_procurement.tabs.'.$tab, $translatedLabel);
                $this->assertSame(1, preg_match(
                    $locale === 'ar' ? '/[\x{0600}-\x{06FF}]/u' : '/[A-Za-z]/',
                    $translatedLabel,
                ));
                $this->assertStringContainsString(e($translatedLabel), $rail);
                $this->actingAs($user)->get($href)->assertOk();
            }

            foreach ($forbiddenTabs as $tab) {
                $href = $this->tabUrl($tab, $store);
                $this->assertNotContains($tab, array_column($actualDestinations, 'tab'));
                $this->actingAs($user)->get($href)->assertForbidden();
            }

            $this->actingAs($user)
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'not-a-workbench-tab',
                ]))
                ->assertNotFound();
        }

        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    public function test_all_eleven_direct_tabs_return_403_if_version_is_not_certified(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();

        // Keep role, actual store permissions, lifecycle, entitlement, health
        // and synthetic signed evidence valid. Only certification is missing.
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        $this->assertSame('uncertified', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue($actor->fresh()->hasPermission($permission, $store->id), "{$locale}/{$tab}");
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $store))
                    ->assertForbidden();
            }
        }
    }

    public function test_all_eleven_direct_tabs_return_403_when_permission_is_explicitly_denied(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();
        $this->denyPermissions($actor, array_values(self::TAB_READ_PERMISSIONS), null);
        $this->assertSame('ready', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertFalse($actor->fresh()->hasPermission($permission, $store->id), "{$locale}/{$tab}");
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $store))
                    ->assertForbidden();
            }
        }
    }

    public function test_all_eleven_direct_tabs_deny_an_excluded_b2b_store_without_denying_another(): void
    {
        $actor = $this->manager();
        $owned = $this->wholesaleStore();
        $foreign = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-FOREIGN-B2B-TABS',
            'name' => 'P135 foreign Wholesale store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreign);

        // Wholesale global roles otherwise span Wholesale stores. Explicit
        // per-store DENY is the genuine authorization boundary here; never
        // claim all B2B stores are foreign to a globally assigned manager.
        $this->denyPermissions($actor, array_values(self::TAB_READ_PERMISSIONS), $foreign->id);
        $this->assertSame('ready', $this->readiness());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue($actor->fresh()->hasPermission($permission, $owned->id), "{$locale}/{$tab}/owned");
                $this->assertFalse($actor->fresh()->hasPermission($permission, $foreign->id), "{$locale}/{$tab}/foreign");

                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $foreign))
                    ->assertForbidden();

                $response = $this->actingAs($actor->fresh())->get($this->tabUrl($tab, $owned));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($tab, $response->viewData('activeTab'));
                $this->assertSame($owned->id, $response->viewData('selectedStoreId'));
            }
        }
    }

    public function test_direct_procurement_http_revokes_inactive_and_archived_roles_even_with_signed_test_readiness(): void
    {
        $manager = $this->manager();
        $store = $this->wholesaleStore();
        $buyer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $buyerRole = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $buyer->roles()->attach($buyerRole);

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())
            ->get($this->tabUrl('suppliers', $store))
            ->assertOk();
        $this->actingAs($buyer->fresh())
            ->get($this->tabUrl('suppliers', $store))
            ->assertOk();

        // A disabled role keeps its stored assignment for audit history,
        // but may no longer supply any operational permission or link.
        Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()
            ->forceFill(['is_active' => false])->save();
        $buyerRole->forceFill([
            'is_active' => false,
            'archived_at' => now(),
        ])->save();

        $before = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ([$manager, $buyer] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->assertFalse(
                    $actor->fresh()->hasPermission('procurement.suppliers.view', $store->id),
                    $locale.' must not inherit inactive/archived role rights',
                );
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl('suppliers', $store))
                    ->assertForbidden();
            }
        }

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $buyer->id,
            'role_id' => $buyerRole->id,
        ]);
    }

    public function test_historically_archived_but_still_active_global_role_cannot_reopen_direct_procurement_http(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();
        $role = Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail();
        $this->assertSame('ready', $this->readiness());

        $this->actingAs($actor->fresh())->get($this->tabUrl('suppliers', $store))->assertOk();

        // Legacy imports can preserve contradictory role rows. The security
        // boundary must reject archived_at even when is_active was left true;
        // hiding the Sidebar is insufficient to protect the direct HTTP route.
        DB::table('roles')->where('id', $role->id)->update([
            'is_active' => true,
            'archived_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertNotNull(DB::table('roles')->where('id', $role->id)->value('archived_at'));

        $beforeAudit = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get($this->tabUrl('suppliers', $store))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index'))
                ->assertForbidden();
        }

        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    public function test_forged_procurement_store_ids_and_retail_to_wholesale_direct_urls_never_cross_channel(): void
    {
        $manager = $this->manager();
        $wholesale = $this->wholesaleStore();
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-RETAIL-CHANNEL-DENY',
            'name' => 'P135 Retail channel scope denial',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $retailActor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retailActor->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'store_id' => $retail->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleless = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($this->tabUrl('suppliers', $wholesale))->assertOk();
        $before = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['suppliers', 'orders', 'receipts'] as $tab) {
                // A Wholesale global Procurement role is not Retail scope.
                $this->actingAs($manager->fresh())
                    ->get($this->tabUrl($tab, $retail))
                    ->assertForbidden();
                $this->actingAs($manager->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'store_id' => 999999999,
                        'tab' => $tab,
                    ]))
                    ->assertForbidden();

                // A Retail assignment does not authorize forged B2B store_id,
                // even with globally enabled and test-only signed Procurement.
                $this->actingAs($retailActor->fresh())
                    ->get($this->tabUrl($tab, $wholesale))
                    ->assertForbidden();
                $this->actingAs($roleless->fresh())
                    ->get($this->tabUrl($tab, $wholesale))
                    ->assertForbidden();
            }
        }

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
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

    private function manager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function tabUrl(string $tab, Store $store): string
    {
        return route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => $tab,
        ]);
    }

    /** @param list<string> $permissions */
    private function denyPermissions(User $actor, array $permissions, ?int $storeId): void
    {
        $rows = [];
        foreach (array_unique($permissions) as $code) {
            $rows[] = [
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => $storeId === null ? 'global' : 'store:'.$storeId,
                'store_id' => $storeId,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('user_permission_overrides')->insert($rows);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
