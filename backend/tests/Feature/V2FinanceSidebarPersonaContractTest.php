<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T353R5: finance navigation is a projection of real permission, module
 * readiness and tenant scope, not a second grant or financial approval role.
 */
final class V2FinanceSidebarPersonaContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_finance_persona_has_authorized_treasury_and_report_links_but_not_approval_or_administration(): void
    {
        $this->store('B2B', 'P2714-WHOLESALE');

        $actor = $this->roleUser('FINANCE', 'p2714-finance@example.test');
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group) => $group['children'])
                ->keyBy('key');

            foreach (['treasury_master', 'reports_center'] as $key) {
                $item = $items->get($key);
                $this->assertIsArray($item, "Missing authorized finance navigation: {$key} ({$locale})");
                $this->assertTrue(Route::has($item['route']), "Unregistered finance route: {$key}");

                $response = $this->actingAs($actor->fresh())
                    ->get(route($item['route'], $item['params'], false));
                $this->assertTrue(
                    $response->getStatusCode() >= 200 && $response->getStatusCode() < 400,
                    "Finance navigation {$key} returned HTTP {$response->getStatusCode()} in {$locale}",
                );
            }

            foreach ([
                'sidebar_role_access',
                'sidebar_layout',
                'notifications_center',
                'retail_store_provisioning',
                'erp_procurement',
                'erp_wms',
            ] as $forbiddenKey) {
                $this->assertFalse($items->has($forbiddenKey), "Finance role leaked {$forbiddenKey}.");
            }
        }

        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $actor->id]);
    }

    public function test_uncertified_finance_modules_are_absent_even_when_the_role_has_finance_permission(): void
    {
        $this->store('B2B', 'P2714-UNCERTIFIED');
        $actor = $this->roleUser('FINANCE', 'p2714-unready@example.test');

        // Lifecycle ENABLED or a broad finance permission must not turn an
        // uncertified enterprise module into a visible operational link.
        config()->set('enterprise_module_readiness.certified_versions', []);

        $items = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertFalse($items->contains('erp_finance'));
        $this->assertFalse($items->contains('erp_treasury'));
        $this->assertTrue($actor->hasPermission('finance.view'));
    }

    public function test_retail_finance_access_is_store_scoped_and_direct_platform_erp_is_denied(): void
    {
        $ownedStore = $this->store('B2C', 'P2714-RETAIL-OWN');
        $foreignStore = $this->store('B2C', 'P2714-RETAIL-FOREIGN');
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownedStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allowedStores = app(OperationalTenantScope::class)->allowedStoreIds($actor, 'finance.view');
        $this->assertContains($ownedStore, $allowedStores);
        $this->assertNotContains($foreignStore, $allowedStores);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertFalse($keys->contains('erp_finance'));
        $this->assertFalse($keys->contains('erp_treasury'));
        $this->assertFalse($keys->contains('treasury_master'));

        $this->actingAs($actor)
            ->get(route('admin.erp.index', [], false))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', ['store_id' => $foreignStore], false))
            ->assertNotFound();

        $roleless = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.treasury.master.index', [], false))
            ->assertForbidden();
    }

    private function roleUser(string $roleCode, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
