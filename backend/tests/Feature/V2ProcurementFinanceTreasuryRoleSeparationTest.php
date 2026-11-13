<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P051: verify the built-in operational role baseline without silently
 * rewriting existing user grants or replacing transaction-level maker/checker.
 */
final class V2ProcurementFinanceTreasuryRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_procurement_accounting_and_treasury_roles_have_distinct_persisted_permission_bundles(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);

        $matrix = [
            'PROCUREMENT_BUYER' => [
                'domain' => 'procurement',
                'module' => 'procurement',
                'allowed' => ['procurement.purchase_requests.manage', 'procurement.purchase_orders.manage'],
                'denied' => ['procurement.purchase_requests.approve', 'procurement.purchase_orders.issue', 'finance.post', 'finance.approve', 'security.approve'],
            ],
            'PROCUREMENT_APPROVER' => [
                'domain' => 'procurement',
                'module' => 'procurement',
                'allowed' => ['procurement.purchase_requests.approve', 'procurement.purchase_orders.issue'],
                'denied' => ['procurement.purchase_requests.manage', 'procurement.purchase_orders.manage', 'finance.post', 'finance.approve', 'security.approve'],
            ],
            'ACCOUNTANT' => [
                'domain' => 'finance',
                'module' => 'finance',
                'allowed' => ['finance.view', 'finance.manage', 'finance.post'],
                'denied' => ['finance.approve', 'procurement.purchase_requests.approve', 'procurement.purchase_orders.issue', 'security.approve'],
            ],
            'AP_OFFICER' => [
                'domain' => 'finance',
                'module' => 'finance',
                'allowed' => ['finance.view', 'finance.manage'],
                'denied' => ['finance.post', 'finance.approve', 'procurement.purchase_orders.issue', 'security.approve'],
            ],
            'TREASURY_OFFICER' => [
                'domain' => 'treasury',
                'module' => 'treasury',
                'allowed' => ['finance.view', 'finance.manage'],
                'denied' => ['finance.post', 'finance.approve', 'procurement.purchase_requests.approve', 'procurement.purchase_orders.issue', 'security.approve'],
            ],
            'TREASURY_MANAGER' => [
                'domain' => 'treasury',
                'module' => 'treasury',
                'allowed' => ['finance.view', 'finance.approve'],
                'denied' => ['finance.post', 'procurement.purchase_requests.approve', 'procurement.purchase_orders.issue', 'security.approve'],
            ],
        ];

        foreach ($matrix as $code => $expectation) {
            $definition = $catalog->definition($code);
            $this->assertNotNull($definition, $code.' must be a canonical built-in role.');
            $this->assertSame($expectation['domain'], $definition['domain'], $code);
            $this->assertContains($expectation['module'], $definition['required_modules'], $code);
            $this->assertSame('global', $definition['scope'], $code);

            $role = Role::query()->where('code', $code)->firstOrFail();
            $this->assertTrue((bool) $role->is_system, $code);

            $persisted = $role->permissions()->pluck('code')->all();
            $this->assertEqualsCanonicalizing($definition['permissions'], $persisted, $code.' persisted grants must match the source-controlled bundle.');

            foreach ($expectation['allowed'] as $permission) {
                $this->assertContains($permission, $persisted, $code.' lost '.$permission);
            }

            foreach ($expectation['denied'] as $permission) {
                $this->assertNotContains($permission, $persisted, $code.' must not inherit '.$permission);
            }
        }

        $this->assertEqualsCanonicalizing(
            ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
            config('job_roles.risk_pairs.procurement_maker_checker.roles'),
        );
    }

    public function test_disabled_modules_fail_closed_for_existing_role_assignments_without_destroying_history(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $registry = app(ModuleRegistry::class);

        foreach ([
            'PROCUREMENT_BUYER' => ['procurement', 'procurement.purchase_requests.manage'],
            'ACCOUNTANT' => ['finance', 'finance.post'],
            'TREASURY_OFFICER' => ['treasury', 'finance.manage'],
        ] as $code => [$module, $permission]) {
            $registry->syncManifest($module);
            $this->assertFalse($registry->isEnabled($module), $module.' must not be active in a clean test install.');

            $role = Role::query()->where('code', $code)->firstOrFail();
            $user = User::factory()->create(['is_active' => true]);
            $user->roles()->attach($role);

            $this->assertFalse($catalog->isAvailable($code), $code);
            $this->assertFalse($user->fresh()->hasPermission($permission), $code);
            $this->assertDatabaseHas('role_user', [
                'user_id' => $user->id,
                'role_id' => $role->id,
            ]);
        }
    }

    public function test_unentitled_builtin_operational_roles_cannot_enter_foreign_erp_workspaces_in_either_language(): void
    {
        foreach ([
            'PROCUREMENT_BUYER' => 'admin.erp.finance.index',
            'ACCOUNTANT' => 'admin.erp.procurement.index',
            'TREASURY_OFFICER' => 'admin.erp.procurement.index',
        ] as $code => $route) {
            $user = User::factory()->create(['is_active' => true]);
            $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $user->forceFill(['locale' => $locale])->save();

                $this->actingAs($user->fresh())
                    ->get(route($route))
                    ->assertForbidden();
            }
        }
    }
}
