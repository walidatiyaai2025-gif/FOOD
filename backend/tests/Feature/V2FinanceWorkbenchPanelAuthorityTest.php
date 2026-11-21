<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Http\Controllers\Admin\FinanceWorkbenchController;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2FinanceWorkbenchPanelAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        // Test-only module certification; production readiness is unchanged.
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_ar_and_ap_subpanels_preserve_authorized_store_filters_dates_and_locale(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-A');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach ([
                'receivables' => ['documents', 'settlements', 'aging'],
                'payables' => ['documents', 'aging'],
            ] as $tab => $panels) {
                foreach ($panels as $panel) {
                    $query = [
                        'store_id' => $store->id,
                        'tab' => $tab,
                        'finance_panel' => $panel,
                        'status' => 'draft',
                        'date_from' => '2026-01-01',
                        'date_to' => '2026-12-31',
                    ];
                    if ($tab === 'payables') {
                        $query['supplier_reference'] = 'SUPPLIER-P303';
                    }
                    $response = $this->actingAs($actor->fresh())
                        ->get(route('admin.erp.finance.index', $query))
                        ->assertOk()
                        ->assertViewHas('tab', $tab)
                        ->assertSee('dir="'.$direction.'"', false)
                        ->assertSee('data-finance-subpanel="'.$panel.'"', false);

                    $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $this->assertSame(1, substr_count($html, 'data-finance-subpanel="'));
                    $this->assertSame(1, preg_match(
                        '/<nav class="finance-tabs foodex-tabs finance-subtabs"[^>]*data-finance-subtabs[^>]*>(.*?)<\\/nav>/s',
                        $html,
                        $nav,
                    ));
                    $this->assertSame(count($panels), substr_count($nav[1], 'data-finance-subtab="'));
                    $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                    $this->assertStringContainsString('name="finance_panel" value="'.$panel.'"', $html);
                    $this->assertStringContainsString('name="date_from" value="2026-01-01"', $html);
                    $this->assertStringContainsString('name="date_to" value="2026-12-31"', $html);
                    $this->assertStringContainsString('flex-wrap:nowrap', $html);
                    foreach ($panels as $candidate) {
                        if ($candidate !== $panel) {
                            $this->assertStringNotContainsString('data-finance-subpanel="'.$candidate.'"', $html);
                        }

                        $this->assertSame(1, preg_match(
                            '/data-finance-subtab="'.preg_quote($candidate, '/').'"[^>]*href="([^"]+)"/s',
                            $nav[1],
                            $link,
                        ));
                        parse_str((string) parse_url($link[1], PHP_URL_QUERY), $parsed);
                        $this->assertSame($tab, $parsed['tab'] ?? null);
                        $this->assertSame($candidate, $parsed['finance_panel'] ?? null);
                        $this->assertSame((string) $store->id, $parsed['store_id'] ?? null);
                        $this->assertSame('draft', $parsed['status'] ?? null);
                        $this->assertSame('2026-01-01', $parsed['date_from'] ?? null);
                        $this->assertSame('2026-12-31', $parsed['date_to'] ?? null);
                    }
                }
            }
        }
    }

    public function test_invalid_array_and_forged_subpanels_fail_to_safe_documents_without_data_writes(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-INVALID');
        $before = [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ];

        foreach (['receivables', 'payables'] as $tab) {
            foreach ([null, 'journals', 'payments', ['aging'], ['other' => 'settlements']] as $panel) {
                $query = ['store_id' => $store->id, 'tab' => $tab];
                if ($panel !== null) {
                    $query['finance_panel'] = $panel;
                }

                $response = $this->actingAs($actor)
                    ->get(route('admin.erp.finance.index', $query))->assertOk();
                $expected = 'documents';
                $this->assertSame(1, substr_count($response->getContent(), 'data-finance-subpanel="'));
                $response->assertSee('data-finance-subpanel="'.$expected.'"', false);
                foreach (['documents', 'settlements', 'aging'] as $candidate) {
                    if ($candidate !== $expected) {
                        $response->assertDontSee('data-finance-subpanel="'.$candidate.'"', false);
                    }
                }
            }
        }

        $this->assertSame($before, [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ]);
    }

    public function test_conflicting_finance_store_id_and_code_cannot_switch_ledger_context(): void
    {
        // The canonical Wholesale principal must exist before this test's
        // disposable B2B stores. Its legacy bootstrap may rename the first
        // active B2B store to FOODEX-WHOLESALE when the canonical store is
        // absent, which otherwise invalidates a stale test-only store code.
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        [$actor, $store] = $this->authorizedViewer('FIN-P303-SELECTOR-OWN');
        $this->assertNotSame($principalStoreId, $store->id);
        $other = $this->createStore('FIN-P303-SELECTOR-OTHER');
        $this->assertSame(
            $store->code,
            (string) DB::table('stores')->where('id', $store->id)->value('code'),
            'The primary fixture store code must be persisted before selector GETs.',
        );
        $this->assertSame(
            $other->code,
            (string) DB::table('stores')->where('id', $other->id)->value('code'),
            'The alternate fixture store code must be persisted before selector GETs.',
        );
        $before = [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            // Each selector is a valid B2B store in this fixture, but the
            // combined pair must never resolve to EITHER financial ledger.
            foreach ([
                ['store_id' => $other->id, 'store_code' => $store->code],
                ['store_id' => $store->id, 'store_code' => $other->code],
                ['store_id' => 'not-a-store', 'store_code' => $store->code],
                ['store_id' => '0', 'store_code' => $store->code],
                ['store_id' => (string) $store->id.'.5', 'store_code' => $store->code],
                ['store_id' => '99999999999999999999999', 'store_code' => $store->code],
                ['store_id' => [$store->id], 'store_code' => $store->code],
                ['store_id' => $store->id, 'store_code' => [$store->code]],
            ] as $selectors) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.finance.index', $selectors + ['tab' => 'receivables']))
                    ->assertNotFound();
            }

            // A GET that rejects a mismatched or forged store selector must
            // never rewrite either store's identity in the database.
            $this->assertSame(
                $store->code,
                (string) DB::table('stores')->where('id', $store->id)->value('code'),
                'Denied Finance selector GETs must not mutate the primary store code.',
            );
            $this->assertSame(
                $other->code,
                (string) DB::table('stores')->where('id', $other->id)->value('code'),
                'Denied Finance selector GETs must not mutate the alternate store code.',
            );

            $this->assertTrue(
                app(FinanceWorkbenchAccess::class)->canRead($actor->fresh(), $store->id),
                'The fixture store must pass Finance read authorization before selector matching.',
            );

            // Probe the exact server-side chooser without weakening any
            // direct GET, mismatch, or forged-selector assertion. These
            // disposable fixture codes are not production account data.
            $financeController = app(FinanceWorkbenchController::class);
            $contextOptions = (new \ReflectionMethod($financeController, 'contextOptions'))->invoke(
                $financeController,
                $actor->fresh(),
                (int) $store->id,
                (string) $store->code,
            );
            $this->assertSame(
                [(int) $store->id],
                array_column($contextOptions, 'id'),
                'A selected wholesale store ID must yield exactly its own authorized context.',
            );
            $this->assertSame(
                0,
                strcasecmp((string) $contextOptions[0]['code'], (string) $store->code),
                sprintf(
                    'Exact-store context code differs: selected=%s, persisted fixture=%s.',
                    (string) $contextOptions[0]['code'],
                    (string) $store->code,
                ),
            );
            $request = Request::create(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'store_code' => $store->code,
                'tab' => 'overview',
            ]), 'GET');
            $selectors = (new \ReflectionMethod($financeController, 'validatedStoreSelectors'))->invoke(
                $financeController,
                $request,
            );
            $this->assertSame(
                [(int) $store->id, (string) $store->code],
                $selectors,
                'Both HTTP store selectors must survive URL construction and validation unchanged.',
            );

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'store_code' => $store->code,
                    'tab' => 'overview',
                ]));

            if ($response->exception instanceof \Throwable) {
                $controllerFrame = collect($response->exception->getTrace())
                    ->first(fn (array $frame): bool => str_contains($frame['file'] ?? '', 'FinanceWorkbenchController.php'));
                $this->fail(sprintf(
                    'A valid matching store_id/store_code pair raised %s at %s:%d; controller=%s:%s',
                    $response->exception::class,
                    $response->exception->getFile(),
                    $response->exception->getLine(),
                    $controllerFrame['function'] ?? 'unknown',
                    $controllerFrame['line'] ?? 'unknown',
                ));
            }

            $response->assertOk()->assertViewHas(
                'selectedContext',
                fn (array $selected): bool => $selected['id'] === $store->id,
            );
        }

        $this->assertSame($before, [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ]);
    }

    public function test_panels_cannot_change_store_or_bypass_finance_access_or_module_lifecycle(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-ALLOW');
        $other = $this->createStore('FIN-P303-UX-FOREIGN');

        foreach (['receivables', 'payables'] as $tab) {
            foreach (['documents', 'aging'] as $panel) {
                $this->actingAs($actor)->get(route('admin.erp.finance.index', [
                    'store_id' => $other->id,
                    'tab' => $tab,
                    'finance_panel' => $panel,
                ]))->assertOk();
            }
        }

        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        $this->actingAs($outsider)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
            'finance_panel' => 'settlements',
        ]))->assertForbidden();

        app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::DISABLED);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'payables',
            'finance_panel' => 'aging',
        ]))->assertForbidden();
    }

    public function test_store_denial_blocks_foreign_settlement_document_without_financial_writes(): void
    {
        [$actor, $actorStore] = $this->authorizedViewer('FIN-P303-G009-DENY');
        $foreignStore = $this->createStore('FIN-P303-G009-FOREIGN');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore((int) $foreignStore->id);
        $this->assertNotNull($scope);

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'G009 foreign store customer',
            'email' => 'g009-foreign-store@example.test',
        ]);
        $now = now();
        $account = static fn (string $code, string $name, string $type, string $normalBalance): int => DB::table(
            'enterprise_finance_accounts',
        )->insertGetId([
            'organization_id' => $scope['organization_id'],
            'company_id' => $scope['company_id'],
            'legal_entity_id' => $scope['legal_entity_id'],
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'allow_posting' => true,
            'status' => 'active',
            'created_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $receivableAccountId = $account('1200-G009-DENY', 'G009 Receivable', 'asset', 'debit');
        $revenueAccountId = $account('4000-G009-DENY', 'G009 Revenue', 'revenue', 'credit');
        $foreignDocumentId = DB::table('enterprise_ar_documents')->insertGetId([
            'organization_id' => $scope['organization_id'],
            'company_id' => $scope['company_id'],
            'legal_entity_id' => $scope['legal_entity_id'],
            'store_id' => $foreignStore->id,
            'customer_id' => $customer->id,
            'document_number' => 'AR-G009-FOREIGN-DENY',
            'idempotency_key' => 'g009-foreign-document-deny',
            'request_hash' => str_repeat('g', 64),
            'document_type' => 'invoice',
            'entry_date' => '2026-11-18',
            'due_date' => '2026-12-18',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $receivableAccountId,
            'offset_account_id' => $revenueAccountId,
            'status' => 'posted',
            'created_by' => $actor->id,
            'posted_at' => $now,
            'posted_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertDatabaseHas('enterprise_ar_documents', [
            'id' => $foreignDocumentId,
            'store_id' => $foreignStore->id,
            'status' => 'posted',
        ]);

        $permissionId = Permission::query()->where('code', 'finance.view')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'store:'.$foreignStore->id,
            'store_id' => $foreignStore->id,
            'effect' => 'deny',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $actorStore->id,
            'tab' => 'receivables',
            'finance_panel' => 'settlements',
        ]))->assertOk();

        $financialRowsBefore = [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ];

        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $foreignStore->id,
            'tab' => 'receivables',
            'finance_panel' => 'settlements',
            'settlement_document_id' => $foreignDocumentId,
        ]))->assertNotFound();

        $this->assertSame($financialRowsBefore, [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_ar_allocations')->count(),
        ]);
    }

    public function test_ar_ap_reader_without_gl_entitlement_sees_no_journal_navigation(): void
    {
        [$viewer, $store] = $this->authorizedViewer('FIN-P303-UX-NO-GL');
        $context = app(TenantContextResolver::class)->wholesale($viewer, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        // Change a disposable test-only entitlement, never production readiness
        // or the enterprise role definitions.
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            ['finance.ar.read', 'finance.ap.read', 'finance.foundation.read'],
            $viewer,
        );

        $journalUrl = route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'journals',
        ]);
        $this->actingAs($viewer)->get($journalUrl)->assertForbidden();

        foreach (['ar' => 'receivables', 'ap' => 'payables'] as $key => $tab) {
            $response = $this->actingAs($viewer)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => $tab,
                'finance_panel' => 'documents',
            ]))->assertOk();
            $markup = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringNotContainsString('href="'.$journalUrl.'"', $markup, $key);
        }

        $overview = $this->actingAs($viewer)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'overview',
        ]))->assertOk()
            ->assertDontSee(__('finance_workbench.metrics.unposted_journals'))
            ->assertDontSee(__('finance_workbench.metrics.pending_approvals'));
        $this->assertStringNotContainsString(
            'href="'.$journalUrl.'&status=draft"',
            html_entity_decode($overview->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    public function test_ar_only_authorized_reader_gets_no_payables_or_gl_navigation_or_ledger_data(): void
    {
        [$reader, $store] = $this->authorizedViewer('FIN-P303-UX-AR-ONLY');
        $context = app(TenantContextResolver::class)->wholesale($reader, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        // Only disposable test entitlements are restricted. The runtime module
        // certification, role catalog, and actual finance authority stay intact.
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            ['finance.ar.read'],
            $reader,
        );

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $reader->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($reader->fresh())->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
                'finance_panel' => 'documents',
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-finance-subpanel="documents"', false)
                ->assertViewHas('data', static fn (array $data): bool => ($data['periods'] ?? null) instanceof Collection);

            $markup = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match('/<nav class="finance-tabs"[^>]*>(.*?)<\/nav>/s', $markup, $nav));
            $this->assertStringContainsString('tab=receivables', $nav[1]);
            $this->assertStringNotContainsString(__('finance_workbench.tabs.journals'), $nav[1]);
            $this->assertStringNotContainsString(__('finance_workbench.tabs.payables'), $nav[1]);
            $this->assertStringNotContainsString('is-disabled', $nav[1]);
            $this->assertStringNotContainsString('tab=journals', $nav[1]);
            $this->assertStringNotContainsString('tab=payables', $nav[1]);

            foreach (['journals', 'payables'] as $deniedTab) {
                $this->actingAs($reader->fresh())->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => $deniedTab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_mixed_ar_officer_and_finance_role_cannot_read_gl_ap_or_mutate_ledger(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-AR-DUTY-MIX');
        $actor->roles()->attach(Role::query()->where('code', 'AR_OFFICER')->firstOrFail()->id);
        $journalsBefore = DB::table('enterprise_finance_journals')->count();
        $ledgerBefore = DB::table('enterprise_finance_ledger_entries')->count();

        // A second B2B role admits a Finance HTTP context; it must not
        // expand the current AR_OFFICER duty through shared finance.view.
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();

            $this->assertTrue($user->hasRole('AR_OFFICER'));
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('tab', 'receivables')
                ->assertViewHas('access', static fn (array $access): bool => $access['ar_read']
                    && ! $access['gl_read']
                    && ! $access['ap_read']
                    && ! $access['foundation_read']);

            foreach (['journals', 'payables', 'accounts', 'opening_balances'] as $tab) {
                $this->actingAs($user)->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))->assertForbidden();
            }

            $this->actingAs($user)->post(route('admin.erp.finance.journals.store'), [
                'store_id' => $store->id,
                'description' => 'Denied cross-role general ledger creation',
                'idempotency_key' => 'p303-ar-duty-no-gl',
            ])->assertForbidden();
        }

        $this->assertSame($journalsBefore, DB::table('enterprise_finance_journals')->count());
        $this->assertSame($ledgerBefore, DB::table('enterprise_finance_ledger_entries')->count());
    }

    public function test_uncertified_finance_module_does_not_render_even_an_ar_document_tab(): void
    {
        [$actor, $store] = $this->authorizedViewer('FIN-P303-UX-UNREADY');
        config()->set('enterprise_module_readiness.certified_versions.finance', null);

        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
            'finance_panel' => 'documents',
        ]))->assertForbidden();
    }

    public function test_gl_links_project_existing_read_permission_not_journal_ids_alone(): void
    {
        $template = file_get_contents(resource_path('views/admin/finance-workbench.blade.php'));
        $this->assertIsString($template);
        // The Receivable document actions have an inner GL capability guard.
        $this->assertStringContainsString(
            "@if(\$document->journal_id && (\$access['gl_read'] ?? false))",
            $template,
        );
        // AP and settlement reports hide their entire GL-only table column.
        $this->assertStringContainsString(
            "@if(\$access['gl_read'] ?? false)<td>@if(\$document->journal_id)",
            $template,
        );
        $this->assertStringContainsString(
            "@if(\$access['gl_read'] ?? false)<td>@if(\$settlement->journal_id)",
            $template,
        );
        $this->assertStringContainsString(
            "@if(\$access['gl_read'] ?? false)<th>{{ __('finance_workbench.actions.view_ledger') }}</th>@endif",
            $template,
        );
        $this->assertStringContainsString(
            "5 => (bool) (\$access['gl_read'] ?? false)",
            $template,
        );
    }

    public function test_retail_finance_requires_module_entitlement_even_with_store_permission(): void
    {
        $store = $this->createRetailStore('FIN-P303-RETAIL-BLOCKED');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->firstOrCreate(['code' => 'finance.view'], ['name' => 'finance.view'])->id,
        ]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
            'tab' => 'receivables',
        ]))->assertForbidden();
    }

    public function test_retail_finance_uses_only_entitled_assigned_store_and_denies_sibling(): void
    {
        $store = $this->createRetailStore('FIN-P303-RETAIL-ENTITLED');
        $sibling = $this->createRetailStore('FIN-P303-RETAIL-SIBLING');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->firstOrCreate(['code' => 'finance.view'], ['name' => 'finance.view'])->id,
        ]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            ['finance.ar.read'],
            $actor,
        );

        $before = [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_finance_ledger_entries')->count(),
        ];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->assertFalse((bool) ($response->viewData('data')['legal_entity_reporting_available'] ?? false));

            $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
                'store_id' => $sibling->id,
                'tab' => 'receivables',
            ]))->assertNotFound();
        }

        $this->assertSame($before, [
            DB::table('enterprise_finance_journals')->count(),
            DB::table('enterprise_finance_ledger_entries')->count(),
        ]);
    }

    /** @return array{User, Store} */
    private function authorizedViewer(string $code): array
    {
        $store = $this->createStore($code);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'finance.view'],
            ['name' => 'finance.view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $actor->roles()->attach($role->id);

        $context = app(TenantContextResolver::class)->wholesale($actor, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            app(ModuleLifecycleManager::class)->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.ar.read',
                'finance.ap.read',
                'finance.credit_terms.read',
            ],
            $actor,
        );

        return [$actor, $store];
    }

    private function createStore(string $code): Store
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

    private function createRetailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
