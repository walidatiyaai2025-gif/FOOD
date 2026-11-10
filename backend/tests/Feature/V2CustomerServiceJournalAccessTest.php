<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\EnterpriseModuleAdministration;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CustomerServiceJournalAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_ROLES = [
        'CUSTOMER_SERVICE_MANAGER',
        'CUSTOMER_SERVICE_SUPERVISOR',
        'CUSTOMER_SERVICE_AGENT',
        'CUSTOMER_SUPPORT',
        'RETAIL_CUSTOMER_SUPPORT',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_role_baselines_never_grant_finance_journal_permissions(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);

        foreach (self::SERVICE_ROLES as $code) {
            $role = Role::query()->where('code', $code)->firstOrFail();
            $definition = $catalog->definition($code);

            $this->assertNotNull($definition, $code);
            $this->assertSame([], $this->financePermissions((array) config('permissions.roles.'.$code, [])), $code.' legacy baseline');
            $this->assertSame([], $this->financePermissions((array) ($definition['permissions'] ?? [])), $code.' catalog baseline');
            $this->assertSame([], $this->financePermissions($role->permissions()->pluck('code')->all()), $code.' seeded permissions');
            $this->assertContains('orders.view', $definition['permissions'], $code.' remains operational');
        }

        $this->assertTrue($this->userWithRole('FINANCE')->hasPermission('finance.view'));
    }

    public function test_service_roles_cannot_open_journal_or_create_it_even_with_erp_module_enabled(): void
    {
        $owner = $this->userWithRole('SUPER_ADMIN');
        $storeId = app(WholesalePrincipal::class)->storeId();

        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set('finance', EntitlementScope::platform(), true, null, $owner);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled('finance'));
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'finance',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);

        foreach (array_slice(self::SERVICE_ROLES, 0, 4) as $code) {
            $actor = $this->userWithRole($code);

            $this->assertTrue($actor->hasPermission('orders.view'), $code);
            $this->assertFalse($actor->hasPermission('finance.view', $storeId), $code);
            $this->assertFalse($actor->hasPermission('finance.manage', $storeId), $code);
            $this->assertFalse($actor->hasPermission('finance.post', $storeId), $code);

            $navKeys = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'] ?? [])
                ->pluck('key')
                ->all();
            $this->assertNotContains('erp_finance', $navKeys, $code);

            foreach (['en', 'ar'] as $locale) {
                $actor->update(['locale' => $locale]);

                $this->actingAs($actor)
                    ->get(route('admin.erp.finance.index', ['store_id' => $storeId, 'tab' => 'journals']))
                    ->assertForbidden();

                $this->actingAs($actor)
                    ->get(route('admin.erp.finance.index', ['store_id' => $storeId, 'tab' => 'statements']))
                    ->assertForbidden();

                $this->actingAs($actor)
                    ->post(route('admin.erp.finance.journals.store'), [
                        'store_id' => $storeId,
                        'idempotency_key' => 'denied-'.$code.'-'.$locale,
                        'entry_date' => '2026-11-10',
                        'description' => 'Unauthorized journal',
                        'lines' => [],
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseCount('enterprise_finance_journals', 0);
    }

    public function test_role_reconciliation_removes_stale_finance_grant_without_erasing_service_workflow(): void
    {
        $actor = $this->userWithRole('CUSTOMER_SERVICE_MANAGER');
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();
        $financeView = Permission::query()->where('code', 'finance.view')->firstOrFail();
        $ordersView = Permission::query()->where('code', 'orders.view')->firstOrFail();

        // Simulate an old/bad permission pivot: seeded built-in defaults are authoritative.
        $role->permissions()->syncWithoutDetaching([$financeView->id]);
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));

        app(BuiltInJobRoleCatalog::class)->reconcile();

        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('orders.view'));
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $financeView->id,
        ]);
        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $ordersView->id,
        ]);
    }

    /** @param array<int, mixed> $permissions
     * @return list<string>
     */
    private function financePermissions(array $permissions): array
    {
        return array_values(array_filter(
            array_map('strval', $permissions),
            static fn (string $code): bool => str_starts_with($code, 'finance.'),
        ));
    }

    private function userWithRole(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }
}
