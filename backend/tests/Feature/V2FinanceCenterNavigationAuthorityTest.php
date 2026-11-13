<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2610 / P280: the existing Finance Center is an authorized, live
 * business-domain destination, never an independent Sidebar permission.
 */
final class V2FinanceCenterNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_entitled_finance_reader_discovers_one_live_finance_center_in_both_locales(): void
    {
        [$actor, $store, $context] = $this->financeReader('V2-P280-OWN');
        $this->enableFinance($actor, $context);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $finance = $groups->firstWhere('key', 'finance');

            $this->assertIsArray($finance, $locale.' Finance business group must be discoverable');
            $items = collect($finance['children'])->where('key', 'erp_finance');
            $this->assertCount(1, $items, $locale.' Finance Center must not be duplicated');

            $item = $items->first();
            $this->assertSame('admin.erp.finance.index', $item['route']);
            $this->assertTrue(Route::has($item['route']));
            $this->assertSame('admin.erp_center.modules.finance.title', $item['label']);
            $this->assertNotSame($item['label'], __($item['label']));

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], ['store_id' => $store->id], false))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_disabled_finance_module_has_no_finance_center_and_cannot_open_direct_url(): void
    {
        [$actor, $store] = $this->financeReader('V2-P280-OFF');

        $this->assertFalse($this->hasFinanceEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
    }

    public function test_explicit_finance_view_deny_blocks_navigation_and_direct_http_without_changing_data(): void
    {
        [$actor, $store, $context] = $this->financeReader('V2-P280-DENY');
        $this->enableFinance($actor, $context);
        $this->assertTrue($this->hasFinanceEntry($actor));

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($this->hasFinanceEntry($actor->fresh()));
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
    }

    public function test_foreign_retail_store_is_not_a_finance_context_for_the_authorized_reader(): void
    {
        [$actor, $store, $context] = $this->financeReader('V2-P280-SCOPE');
        $this->enableFinance($actor, $context);
        $foreign = $this->retailStore('V2-P280-FOREIGN');

        $this->assertTrue($this->hasFinanceEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $foreign->id], false))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertOk();
    }

    public function test_roleless_and_inactive_users_cannot_open_the_finance_center(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        [$inactive, $store] = $this->financeReader('V2-P280-INACTIVE');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->assertFalse($this->hasFinanceEntry($actor));
            $this->actingAs($actor)
                ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
                ->assertForbidden();
        }
    }

    private function hasFinanceEntry(User $actor): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'erp_finance');
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function financeReader(string $storeCode): array
    {
        $store = $this->retailStore($storeCode);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();
        $view = Permission::query()->where('code', 'finance.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$view->id]);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, app(TenantContextResolver::class)->retail($actor, $store->id)];
    }

    private function retailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        // The exact legal-entity entitlement is required by the Finance
        // controller; the store entitlement makes the authorized business
        // destination visible to the existing Sidebar composer.
        $manager = app(ModuleEntitlementManager::class);
        $manager->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.ar.read',
                'finance.ap.read',
            ],
            $actor,
        );
        $manager->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $context->storeId),
            true,
            null,
            $actor,
        );
    }
}
