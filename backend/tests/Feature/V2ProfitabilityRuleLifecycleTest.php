<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialFeatureFlags;
use App\Services\CommercialProfitabilityRuleService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2ProfitabilityRuleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        app(CommercialFeatureFlags::class)->persist([
            CommercialFeatureFlags::COMMERCIAL_RULES => true,
        ]);
    }

    public function test_inclusive_single_day_margin_window_and_reversed_dates_remain_safe_in_both_locales(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $singleDay = now()->addDays(3)->toDateString();
        $previousDay = now()->addDays(2)->toDateString();

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($maker->fresh())->put($route, [
                ...$this->proposal((string) Str::uuid(), '17.25'),
                'effective_from' => $singleDay,
                'effective_until' => $singleDay,
            ])->assertSessionHasNoErrors();

            $this->actingAs($maker->fresh())->put($route, [
                ...$this->proposal((string) Str::uuid(), '17.25'),
                'effective_from' => $singleDay,
                'effective_until' => $previousDay,
            ])->assertSessionHasErrors('effective_until');
        }

        $this->assertSame(2, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.proposed')->count());
        $this->assertSame(0, DB::table('product_commercial_policies')->count());
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'store_id' => $store,
            'product_id' => $product,
            'status' => 'DRAFT',
            'minimum_margin_basis_points' => 1725,
            'effective_from' => $singleDay,
            'effective_until' => $singleDay,
        ]);

        $this->restrictB2bAdminToIndependentChecker();
        $checker = $this->user('B2B_ADMIN');
        $revisionIds = DB::table('commercial_profitability_rule_revisions')
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $revisionIds[0],
            'decision' => 'review',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revisionIds[0],
            'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);

        // Inclusive overlap must prevent a second independently reviewed floor
        // for the very same date, without silently activating business pricing.
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $revisionIds[1],
            'decision' => 'review',
        ])->assertSessionHasErrors('revision_id');
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revisionIds[1],
            'status' => 'DRAFT',
            'reviewed_by' => null,
        ]);
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')
            ->where('status', 'ACTIVE')->count());
    }

    public function test_profitability_configuration_help_and_blocked_readiness_are_bilingual_without_default_margin(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $page = route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]);

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($maker->fresh())->get($page)->assertOk()
                ->assertSee(__('commercial.profitability.readiness_label', [], $locale))
                ->assertSee(__('commercial.profitability.readiness_blocked', [], $locale))
                ->assertSee(__('commercial.profitability.help_source', [], $locale))
                ->assertSee(__('commercial.profitability.help_default', [], $locale))
                ->assertSee(__('commercial.profitability.help_permissions', [], $locale))
                ->assertSee(__('commercial.profitability.test_inputs', [], $locale))
                ->assertSee('data-v2-p229-readiness', false)
                ->assertSee('data-readiness-state="blocked"', false)
                ->assertSee('name="profitability_action" value="validate_proposal"', false)
                ->assertSee('placeholder="12.50"', false);
        }

        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
    }

    public function test_scoped_test_inputs_uses_save_validator_without_finance_or_audit_writes(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $save = $this->saveRoute($store, $product);
        $payload = [
            ...$this->proposal((string) Str::uuid(), '12.50'),
            'profitability_action' => 'validate_proposal',
        ];

        foreach (['en', 'ar'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($maker->fresh())->put($save, $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', __('commercial.profitability.validated_inactive', [], $locale));

            $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());
            $this->assertSame(0, DB::table('product_commercial_policies')->count());

            $this->actingAs($maker->fresh())->put($save, [
                ...$payload, 'minimum_margin_percent' => '100.01',
            ])->assertSessionHasErrors('minimum_margin_percent');
            $this->actingAs($maker->fresh())->put($save, [
                ...$payload, 'untrusted_cost_basis' => '42',
            ])->assertSessionHasErrors('untrusted_cost_basis');
            $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
        }

        // Testing never consumes the hidden idempotency key: Save uses the
        // same original UUID but still creates only an inactive draft.
        $this->actingAs($maker->fresh())->put($save, [
            ...$payload, 'profitability_action' => 'propose',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'request_id' => $payload['request_id'], 'status' => 'DRAFT',
        ]);
    }

    public function test_catalog_view_only_role_cannot_read_confidential_profitability_revisions(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $save = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($save, $this->proposal((string) Str::uuid(), '67.89'))
            ->assertSessionHasNoErrors();

        // A catalog reader may browse saleable products, but the role has no
        // commercial policy write, financial approval or profitability access.
        // B2B_ADMIN is a registered wholesale global-role template; narrow
        // its actual grants to read-only catalog access for this test.
        // An arbitrary custom global role is intentionally not auto-scoped.
        $viewerRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $viewerRole->permissions()->sync(Permission::query()
            ->whereIn('code', ['stores.view', 'catalog.view'])
            ->pluck('id')
            ->all());
        /** @var User $viewer */
        $viewer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $viewer->roles()->attach($viewerRole);

        $page = route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]);
        foreach (['ar', 'en'] as $locale) {
            $viewer->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($viewer)->get($page)->assertOk()
                ->assertDontSee('67.89%')
                ->assertDontSee('data-v2-p229-profitability', false);
        }

        // Unauthorized mutations conceal the B2B store scope with 404.
        $this->actingAs($viewer)->put($save, $this->proposal((string) Str::uuid(), '67.89'))
            ->assertNotFound();
        // The control-plane shortcut grants exceptional store scope, not
        // daily access to confidential profitability history.
        $this->actingAs($this->user('SUPER_ADMIN'))->get($page)->assertOk()
            ->assertDontSee('67.89%')
            ->assertDontSee('data-v2-p229-profitability', false);
    }

    public function test_profitability_revision_read_service_enforces_exact_actor_store_and_product(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $this->actingAs($maker)->put(
            $this->saveRoute($store, $product),
            $this->proposal((string) Str::uuid(), '19.37'),
        )->assertSessionHasNoErrors();

        $registry = app(CommercialProfitabilityRuleService::class);
        $this->assertCount(1, $registry->revisions($maker, $store, $product));

        // The service must deny unauthorized direct callers, even if a new
        // endpoint were to bypass the controller's section-visibility guard.
        $superAdmin = $this->user('SUPER_ADMIN');
        foreach ([
            [$superAdmin, $store, $product, 403],
            [$maker, $store, $product + 999999, 404],
        ] as [$actor, $scopeStore, $scopeProduct, $expectedStatus]) {
            $caught = null;
            try {
                $registry->revisions($actor, $scopeStore, $scopeProduct);
            } catch (HttpException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(HttpException::class, $caught);
            $this->assertSame($expectedStatus, $caught->getStatusCode());
        }

        // Catalog discovery alone is insufficient to read business-sensitive
        // revisions, even for an active user attached to an operational role.
        Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()
            ->permissions()->sync(Permission::query()
            ->whereIn('code', ['stores.view', 'catalog.view'])
            ->pluck('id')->all());
        $catalogReader = $this->user('B2B_ADMIN');
        $caught = null;
        try {
            $registry->revisions($catalogReader, $store, $product);
        } catch (HttpException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertSame(403, $caught->getStatusCode());
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.proposed')->count());
    }

    public function test_foreign_catalog_product_cross_link_cannot_be_managed_as_own_store(): void
    {
        [$maker, $store, $product] = $this->fixture();
        [, , $foreignProduct] = $this->fixture();

        // Simulate an inconsistent/forged store_products pivot: its existence
        // must never substitute for proof that the catalog belongs to store.
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $foreignProduct,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]))->assertOk();

        $this->actingAs($maker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $foreignProduct,
        ]))->assertNotFound();

        $this->actingAs($maker)->put(
            $this->saveRoute($store, $foreignProduct),
            $this->proposal((string) Str::uuid(), '18.25'),
        )->assertNotFound();

        $caught = null;
        try {
            $registry = app(CommercialProfitabilityRuleService::class);
            $registry->revisions($maker, $store, $foreignProduct);
        } catch (HttpException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertSame(404, $caught->getStatusCode());
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'like', 'commercial.profitability_rule.%')->count());
    }

    public function test_scoped_proposal_uses_exact_basis_points_and_request_idempotency_without_activating_prices(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $key = (string) Str::uuid();
        $payload = $this->proposal($key, '12.34');
        $route = $this->saveRoute($store, $product);

        $this->actingAs($maker)->put($route, $payload)
            ->assertRedirect(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->assertSessionHasNoErrors();

        $rule = DB::table('commercial_profitability_rule_revisions')->sole();
        $this->assertSame('DRAFT', $rule->status);
        $this->assertSame(1234, (int) $rule->minimum_margin_basis_points);
        $this->assertSame(1, (int) $rule->revision);
        $this->assertSame((int) $maker->id, (int) $rule->proposed_by);
        $this->assertNull($rule->reviewed_by);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());
        $this->assertSame(0, DB::table('product_commercial_policies')->where('product_id', $product)->count());

        $this->actingAs($maker)->put($route, $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());

        $this->actingAs($maker)->put($route, $this->proposal($key, '12.35'))
            ->assertSessionHasErrors('request_id');
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());

        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '12.35'))
            ->assertSessionHasNoErrors();
        $rules = DB::table('commercial_profitability_rule_revisions')->orderBy('revision')->get();
        $this->assertSame([1, 2], $rules->pluck('revision')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([1234, 1235], $rules->pluck('minimum_margin_basis_points')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(['DRAFT', 'DRAFT'], $rules->pluck('status')->all());
    }

    public function test_another_authorized_maker_cannot_replay_a_different_makers_request_id(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $otherMaker = $this->user('B2B_ADMIN');
        $key = (string) Str::uuid();
        $payload = $this->proposal($key, '12.34');
        $route = $this->saveRoute($store, $product);

        $this->actingAs($maker)->put($route, $payload)->assertSessionHasNoErrors();
        $this->actingAs($otherMaker)->put($route, $payload)->assertSessionHasErrors('request_id');

        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'request_id' => $key,
            'proposed_by' => $maker->id,
            'status' => 'DRAFT',
        ]);
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.proposed')->count());
    }

    public function test_tampered_margin_or_effective_dates_cannot_be_reviewed_read_or_replayed(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $firstPayload = $this->proposal((string) Str::uuid(), '12.34');
        $secondPayload = $this->proposal((string) Str::uuid(), '13.56');

        $this->actingAs($maker)->put($route, $firstPayload)->assertSessionHasNoErrors();
        $this->actingAs($maker)->put($route, $secondPayload)->assertSessionHasNoErrors();
        $ids = DB::table('commercial_profitability_rule_revisions')
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Simulate an out-of-band database corruption of the author-approved
        // margin and a separate effective-date window. The old digest remains.
        DB::table('commercial_profitability_rule_revisions')->where('id', $ids[0])
            ->update(['minimum_margin_basis_points' => 9999]);
        DB::table('commercial_profitability_rule_revisions')->where('id', $ids[1])
            ->update(['effective_until' => now()->addYear()->toDateString()]);

        $maker->forceFill(['locale' => 'en'])->save();
        $this->actingAs($maker->fresh())->put($route, $firstPayload)
            ->assertSessionHasErrors('revision_id');

        $this->restrictB2bAdminToIndependentChecker();
        $checker = $this->user('B2B_ADMIN');

        foreach (['en', 'ar'] as $index => $locale) {
            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($checker->fresh())->put($route, [
                'profitability_action' => 'review',
                'decision' => 'review',
                'revision_id' => $ids[$index],
            ])->assertSessionHasErrors('revision_id');

            $this->actingAs($checker->fresh())->get(route('admin.commercial.sales-control', [
                'store_id' => $store, 'product' => $product,
            ]))->assertRedirect()->assertSessionHasErrors('revision_id');
        }

        $caught = null;
        try {
            app(CommercialProfitabilityRuleService::class)->revisions($checker, $store, $product);
        } catch (ValidationException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught);
        $this->assertArrayHasKey('revision_id', $caught->errors());

        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $ids[0], 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $ids[1], 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.proposed')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.reviewed')->count());
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')
            ->where('status', 'ACTIVE')->count());
    }

    public function test_impossible_review_state_never_becomes_visible_or_approved_by_a_forged_status(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $page = route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]);
        $payload = $this->proposal((string) Str::uuid(), '18.75');

        $this->actingAs($maker)->put($route, $payload)->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');
        $checker = $this->user('B2B_ADMIN');
        $date = now()->toDateTimeString();

        // Content digest still matches: only the unchecked status/checker
        // columns are forged. Never confuse an inactive proposal with ACTIVE.
        $invalidStates = [
            ['status' => 'ACTIVE', 'reviewed_by' => $checker->id, 'reviewed_at' => $date],
            ['status' => 'REVIEWED_INACTIVE', 'reviewed_by' => null, 'reviewed_at' => $date],
            ['status' => 'REVIEWED_INACTIVE', 'reviewed_by' => $maker->id, 'reviewed_at' => $date],
            ['status' => 'REJECTED', 'reviewed_by' => $checker->id, 'reviewed_at' => null],
            ['status' => 'DRAFT', 'reviewed_by' => $checker->id, 'reviewed_at' => $date],
        ];

        foreach ($invalidStates as $state) {
            DB::table('commercial_profitability_rule_revisions')->where('id', $revision)
                ->update($state);

            $this->actingAs($maker)->get($page)
                ->assertRedirect()->assertSessionHasErrors('revision_id');
            $this->actingAs($maker)->put($route, $payload)
                ->assertSessionHasErrors('revision_id');

            $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
                'id' => $revision, 'status' => $state['status'],
            ]);
        }

        $this->restrictB2bAdminToIndependentChecker();

        foreach ($invalidStates as $state) {
            DB::table('commercial_profitability_rule_revisions')->where('id', $revision)
                ->update($state);

            $this->actingAs($checker)->put($route, [
                'profitability_action' => 'review',
                'revision_id' => $revision,
                'decision' => 'review',
            ])->assertSessionHasErrors('revision_id');
        }

        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.reviewed')->count());

        // Repairing the historical record to its untouched original DRAFT
        // must restore legitimate independent review, still never activation.
        DB::table('commercial_profitability_rule_revisions')->where('id', $revision)
            ->update(['status' => 'DRAFT', 'reviewed_by' => null, 'reviewed_at' => null]);

        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ])->assertSessionHasNoErrors();
        $this->actingAs($checker)->get($page)->assertOk();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision, 'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.reviewed')->count());
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')
            ->where('status', 'ACTIVE')->count());
    }

    public function test_independent_finance_checker_can_review_without_catalog_manage_but_cannot_edit_sales_policy(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '12.34'))
            ->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');

        // Checker receives read-only catalog discovery plus two independent
        // approval grants. Catalog authoring is deliberately withheld.
        $checkerRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $grants = Permission::query()
            ->whereIn('code', ['catalog.view', 'orders.approve', 'finance.approve'])
            ->pluck('id')->all();
        $this->assertCount(3, $grants);
        $checkerRole->permissions()->sync($grants);
        $checker = $this->user('B2B_ADMIN');
        $this->assertFalse($checker->hasPermission('catalog.manage'));

        $this->actingAs($checker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]))->assertOk()->assertSee('12.34%');
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision,
            'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);

        // Review eligibility never confers a proposal or normal sales-policy
        // mutation permission, even on the same canonical PUT endpoint.
        $this->actingAs($checker)->put($route, $this->proposal((string) Str::uuid(), '13.00'))
            ->assertNotFound();
        $this->actingAs($checker)->put($route, ['status' => 'OPEN'])
            ->assertNotFound();

        // Both approval permissions remain mandatory; an authorized catalog
        // reader cannot turn one approval into a two-person financial decision.
        foreach ([
            ['catalog.view', 'orders.approve'],
            ['catalog.view', 'finance.approve'],
        ] as $reducedGrants) {
            $checkerRole->permissions()->sync(Permission::query()
                ->whereIn('code', $reducedGrants)->pluck('id')->all());
            $this->actingAs($checker)->put($route, [
                'profitability_action' => 'review',
                'revision_id' => $revision,
                'decision' => 'review',
            ])->assertForbidden();
        }
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
    }

    public function test_independent_checker_review_remains_inactive_and_is_idempotent(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $checker = $this->user('B2B_ADMIN');
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '99.99'))
            ->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');

        // The checker authors a separate draft while still assigned the maker
        // permission, then is restricted to the three independent review grants.
        $this->actingAs($checker)->put($route, $this->proposal((string) Str::uuid(), '8.88'))
            ->assertSessionHasNoErrors();
        $selfRevision = (int) DB::table('commercial_profitability_rule_revisions')
            ->orderByDesc('revision')->value('id');
        $this->restrictB2bAdminToIndependentChecker();

        $review = [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ];

        $this->actingAs($maker)->put($route, $review)->assertForbidden();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', ['id' => $revision, 'status' => 'DRAFT']);

        $this->actingAs($checker)->put($route, $review)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision,
            'status' => 'REVIEWED_INACTIVE',
            'reviewed_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.reviewed')->count());

        $this->actingAs($checker)->put($route, $review)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'commercial.profitability_rule.reviewed')->count());

        $this->actingAs($checker)->put($route, [...$review, 'decision' => 'reject'])
            ->assertSessionHasErrors('revision_id');
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision, 'status' => 'REVIEWED_INACTIVE',
        ]);

        $this->actingAs($checker)->get(route('admin.commercial.sales-control', [
            'store_id' => $store, 'product' => $product,
        ]))->assertOk()
            ->assertSee(__('commercial.profitability.statuses.reviewed_inactive'))
            ->assertSee(__('commercial.profitability.inactive_explanation'));

        // A checker cannot approve the separate revision that they authored.
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review',
            'revision_id' => $selfRevision,
            'decision' => 'review',
        ])->assertForbidden();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $selfRevision, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);
    }

    public function test_independent_review_prevents_overlapping_inactive_margin_windows_in_both_locales(): void
    {
        $checkerRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $originalPermissions = $checkerRole->permissions()->pluck('permissions.id')->all();

        foreach (['en', 'ar'] as $locale) {
            $checkerRole->permissions()->sync($originalPermissions);
            [$maker, $store, $product] = $this->fixture();
            $route = $this->saveRoute($store, $product);

            // Multiple proposals can coexist before commercial review; only
            // one overlapping period may receive reviewed-inactive status.
            $this->actingAs($maker)->put(
                $route, $this->proposal((string) Str::uuid(), '15.00'),
            )->assertSessionHasNoErrors();
            $this->actingAs($maker)->put(
                $route, $this->proposal((string) Str::uuid(), '25.00'),
            )->assertSessionHasNoErrors();

            // Prepare the adjacent, non-overlapping proposal before the role
            // is reduced to its independent-checker grants.
            $next = [
                ...$this->proposal((string) Str::uuid(), '30.00'),
                'effective_from' => now()->addMonth()->addDay()->toDateString(),
                'effective_until' => now()->addMonths(2)->toDateString(),
            ];
            $this->actingAs($maker)->put($route, $next)->assertSessionHasNoErrors();

            $ids = DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->orderBy('revision')->pluck('id')->all();
            $this->restrictB2bAdminToIndependentChecker();
            $checker = $this->user('B2B_ADMIN');

            $review = static fn (int $id, string $decision): array => [
                'profitability_action' => 'review',
                'revision_id' => $id,
                'decision' => $decision,
            ];

            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($checker)->put(
                $route, $review((int) $ids[0], 'review'),
            )->assertSessionHasNoErrors();

            $this->actingAs($checker)->put(
                $route, $review((int) $ids[1], 'review'),
            )->assertSessionHasErrors([
                'revision_id' => __('commercial.profitability.overlapping_window', [], $locale),
            ]);
            $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
                'id' => $ids[1], 'status' => 'DRAFT', 'reviewed_by' => null,
            ]);

            // Rejection of the conflicting draft is still allowed.
            $this->actingAs($checker)->put(
                $route, $review((int) $ids[1], 'reject'),
            )->assertSessionHasNoErrors();

            // An adjacent period starting on the next day is not overlapping.
            $this->actingAs($checker)->put(
                $route, $review((int) $ids[2], 'review'),
            )->assertSessionHasNoErrors();

            $this->assertSame(2, DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->where('status', 'REVIEWED_INACTIVE')->count());
            $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')
                ->where('store_id', $store)->where('product_id', $product)
                ->where('status', 'REJECTED')->count());
            $this->assertSame(3, DB::table('audit_logs')
                ->where('event', 'commercial.profitability_rule.reviewed')
                ->count() - (($locale === 'ar') ? 3 : 0));
            $this->assertSame(0, DB::table('product_commercial_policies')
                ->where('product_id', $product)->count());

            $checkerRole->permissions()->sync($originalPermissions);
        }
    }

    public function test_bilingual_review_and_rejection_states_are_visible_and_non_operational(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '0.01'))
            ->assertSessionHasNoErrors();
        $id = (int) DB::table('commercial_profitability_rule_revisions')->value('id');

        $this->restrictB2bAdminToIndependentChecker();
        $checker = $this->user('B2B_ADMIN');
        $this->actingAs($checker)->put($route, [
            'profitability_action' => 'review', 'revision_id' => $id, 'decision' => 'reject',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $id, 'minimum_margin_basis_points' => 1, 'status' => 'REJECTED',
        ]);

        foreach (['ar', 'en'] as $locale) {
            $checker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($checker)->get(route('admin.commercial.sales-control', [
                'store_id' => $store, 'product' => $product,
            ]))->assertOk()
                ->assertSee(__('commercial.profitability.title', [], $locale))
                ->assertSee(__('commercial.profitability.statuses.rejected', [], $locale))
                ->assertSee(__('commercial.profitability.inactive_explanation', [], $locale));
        }
    }

    public function test_untrusted_fields_and_invalid_percentages_are_rejected_before_any_financial_or_audit_write(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $cases = [
            [$this->proposal((string) Str::uuid(), '100.01'), 'minimum_margin_percent'],
            [$this->proposal((string) Str::uuid(), '12.345'), 'minimum_margin_percent'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'stock_owner' => 'FOODEX'], 'stock_owner'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'cost_basis' => 0], 'cost_basis'],
            [[...$this->proposal((string) Str::uuid(), '12.00'), 'profitability_action' => 'activate'], 'profitability_action'],
        ];
        foreach ($cases as [$payload, $errorField]) {
            $this->actingAs($maker)->put($route, $payload)->assertSessionHasErrors($errorField);
        }

        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'like', 'commercial.profitability_rule.%')->count());
    }

    public function test_store_scope_role_and_disabled_module_fail_closed(): void
    {
        [$maker, $store, $product] = $this->fixture();
        [, $otherStore, $otherProduct] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $payload = $this->proposal((string) Str::uuid(), '15');
        /** @var User $roleless */
        $roleless = User::factory()->create(['is_active' => true]);

        // B2B_ADMIN is intentionally a global Wholesale role. Its second B2B
        // store is not a foreign tenant; test the actual Retail channel fence.
        DB::table('stores')->where('id', $otherStore)->update([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
        ]);
        $this->actingAs($roleless)->put($route, $payload)->assertForbidden();
        $this->actingAs($maker)->put($this->saveRoute($otherStore, $otherProduct), $payload)->assertStatus(404);
        $this->actingAs($maker)->put($this->saveRoute($store, $otherProduct), $payload)->assertStatus(404);

        app(CommercialFeatureFlags::class)->persist([
            CommercialFeatureFlags::COMMERCIAL_RULES => false,
        ]);
        $this->actingAs($maker)->put($route, $payload)->assertForbidden();
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
    }

    public function test_store_scoped_deny_overrides_b2b_global_maker_grant_without_affecting_other_stores(): void
    {
        [$maker, $deniedStore, $deniedProduct] = $this->fixture();
        [, $allowedStore, $allowedProduct] = $this->fixture();
        $maker->forceFill(['has_permission_overrides' => true])->save();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $maker->id,
            'permission_id' => Permission::query()->where('code', 'catalog.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$deniedStore,
            'store_id' => $deniedStore,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker)->put(
            $this->saveRoute($deniedStore, $deniedProduct),
            $this->proposal((string) Str::uuid(), '18.50'),
        )->assertStatus(404);
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());

        $this->actingAs($maker)->put(
            $this->saveRoute($allowedStore, $allowedProduct),
            $this->proposal((string) Str::uuid(), '18.50'),
        )->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'store_id' => $allowedStore,
            'product_id' => $allowedProduct,
            'status' => 'DRAFT',
            'proposed_by' => $maker->id,
        ]);
        $this->assertDatabaseMissing('commercial_profitability_rule_revisions', [
            'store_id' => $deniedStore,
            'product_id' => $deniedProduct,
        ]);
    }

    public function test_store_scoped_deny_blocks_b2b_finance_checker_only_in_denied_store(): void
    {
        [$maker, $deniedStore, $deniedProduct] = $this->fixture();
        [, $allowedStore, $allowedProduct] = $this->fixture();

        $this->actingAs($maker)->put(
            $this->saveRoute($deniedStore, $deniedProduct),
            $this->proposal((string) Str::uuid(), '16.75'),
        )->assertSessionHasNoErrors();
        $deniedRevision = (int) DB::table('commercial_profitability_rule_revisions')
            ->where('store_id', $deniedStore)->where('product_id', $deniedProduct)->value('id');

        $this->actingAs($maker)->put(
            $this->saveRoute($allowedStore, $allowedProduct),
            $this->proposal((string) Str::uuid(), '16.75'),
        )->assertSessionHasNoErrors();
        $allowedRevision = (int) DB::table('commercial_profitability_rule_revisions')
            ->where('store_id', $allowedStore)->where('product_id', $allowedProduct)->value('id');

        $this->restrictB2bAdminToIndependentChecker();
        $checker = $this->user('B2B_ADMIN');
        $checker->forceFill(['has_permission_overrides' => true])->save();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $checker->id,
            'permission_id' => Permission::query()->where('code', 'finance.approve')->firstOrFail()->id,
            'scope_key' => 'store:'.$deniedStore,
            'store_id' => $deniedStore,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $review = ['profitability_action' => 'review', 'decision' => 'review'];
        $this->actingAs($checker)->put($this->saveRoute($deniedStore, $deniedProduct), [
            ...$review, 'revision_id' => $deniedRevision,
        ])->assertForbidden();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $deniedRevision, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);

        $this->actingAs($checker)->put($this->saveRoute($allowedStore, $allowedProduct), [
            ...$review, 'revision_id' => $allowedRevision,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $allowedRevision, 'status' => 'REVIEWED_INACTIVE', 'reviewed_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.reviewed')->count());
    }

    public function test_super_admin_and_registry_platform_owner_cannot_act_as_p229_makers_or_checkers(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $route = $this->saveRoute($store, $product);
        $this->actingAs($maker)->put($route, $this->proposal((string) Str::uuid(), '21.25'))
            ->assertSessionHasNoErrors();
        $revision = (int) DB::table('commercial_profitability_rule_revisions')->value('id');

        // A Super Admin may carry an operational role as well; the control-plane
        // identity must not inherit daily finance authority through that role.
        $superAdmin = $this->user('SUPER_ADMIN');
        $superAdmin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        // PLATFORM_OWNER is recognized only through the protected one-owner
        // registry, not merely by attaching the historical role row.
        /** @var User $platformOwner */
        $platformOwner = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $platformOwner->roles()->attach([
            Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail()->getKey(),
            Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->getKey(),
        ]);
        DB::table('platform_owner_registry')->insert([
            'id' => 1,
            'user_id' => $platformOwner->id,
            'provisioned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue($platformOwner->hasRole('PLATFORM_OWNER'));
        $this->assertTrue($platformOwner->hasRole('SUPER_ADMIN'));

        $review = [
            'profitability_action' => 'review',
            'revision_id' => $revision,
            'decision' => 'review',
        ];

        foreach ([$superAdmin, $platformOwner] as $controlPlaneActor) {
            $this->actingAs($controlPlaneActor)->get(route('admin.commercial.sales-control', [
                'store_id' => $store,
                'product' => $product,
            ]))->assertOk()->assertDontSee('21.25%');

            $this->actingAs($controlPlaneActor)->put($route, $review)->assertForbidden();
            $this->actingAs($controlPlaneActor)->put(
                $route,
                $this->proposal((string) Str::uuid(), '13.37'),
            )->assertForbidden();
        }

        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'id' => $revision,
            'status' => 'DRAFT',
            'reviewed_by' => null,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.proposed')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.reviewed')->count());
    }

    public function test_migration_can_drop_an_empty_profitability_revision_table(): void
    {
        $migration = require database_path(
            'migrations/2026_11_16_000001_create_commercial_profitability_rule_revisions.php',
        );

        $migration->down();

        $this->assertFalse(Schema::hasTable('commercial_profitability_rule_revisions'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('commercial_profitability_rule_revisions'));
        $this->assertSame(0, DB::table('commercial_profitability_rule_revisions')->count());
    }

    public function test_migration_refuses_to_drop_non_empty_profitability_revision_history(): void
    {
        [$maker, $store, $product] = $this->fixture();
        $this->actingAs($maker)->put(
            $this->saveRoute($store, $product),
            $this->proposal((string) Str::uuid(), '12.34'),
        )->assertSessionHasNoErrors();

        $migration = require database_path(
            'migrations/2026_11_16_000001_create_commercial_profitability_rule_revisions.php',
        );
        $exception = null;

        try {
            $migration->down();
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame(
            'Refusing to drop commercial_profitability_rule_revisions because immutable proposal/review history exists. Archive it explicitly before rollback.',
            $exception->getMessage(),
        );
        $this->assertSame(1, DB::table('commercial_profitability_rule_revisions')->count());
        $this->assertDatabaseHas('commercial_profitability_rule_revisions', [
            'store_id' => $store,
            'product_id' => $product,
            'status' => 'DRAFT',
            'minimum_margin_basis_points' => 1234,
            'proposed_by' => $maker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'commercial.profitability_rule.proposed')->count());
    }

    /** @return array{User,int,int} */
    private function fixture(): array
    {
        static $counter = 0;
        $counter++;
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P229-LIFECYCLE-'.$counter,
            'name' => 'P229 Lifecycle '.$counter,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker = $this->user('B2B_ADMIN');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P229-UNIT-'.$counter, 'name' => 'Unit',
            'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId, 'channel' => 'b2b', 'code' => 'p229-lifecycle-'.$counter,
            'name' => 'Lifecycle Catalog', 'is_active' => true, 'is_migration_quarantine' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId, 'name' => 'Lifecycle', 'slug' => 'p229-lifecycle-'.$counter,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId, 'category_id' => $categoryId,
            'unit_id' => $unitId, 'sku' => 'P229-LIFECYCLE-'.$counter,
            'name' => 'Lifecycle Product', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId, 'product_id' => $productId,
            'price' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$maker, $storeId, $productId];
    }

    private function restrictB2bAdminToIndependentChecker(): void
    {
        Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()
            ->permissions()->sync(Permission::query()
            ->whereIn('code', ['catalog.view', 'orders.approve', 'finance.approve'])
            ->pluck('id')->all());
    }

    private function user(string $role): User
    {
        /** @var User $user */
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    /** @return array<string,string> */
    private function proposal(string $requestId, string $margin): array
    {
        return [
            'profitability_action' => 'propose',
            'request_id' => $requestId,
            'minimum_margin_percent' => $margin,
            'effective_from' => now()->addDay()->toDateString(),
            'effective_until' => now()->addMonth()->toDateString(),
        ];
    }

    private function saveRoute(int $storeId, int $productId): string
    {
        return route('admin.commercial.sales-control.save', [
            'store_id' => $storeId,
            'product' => $productId,
        ]);
    }
}
