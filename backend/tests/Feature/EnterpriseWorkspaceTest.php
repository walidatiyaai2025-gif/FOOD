<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseWorkspaceComposer;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_finance_actor_sees_only_authorized_finance_workspace_actions(): void
    {
        $user = $this->userWithGlobalRole('FINANCE', 'en');

        $this->actingAs($user)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'finance']))
            ->assertOk()
            ->assertSee('Enterprise Workspaces')
            ->assertSee('Finance reports')
            ->assertSee('Action Center')
            ->assertDontSee('Order operations')
            ->assertSee('dir="ltr"', false);

        $this->actingAs($user)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'sales']))
            ->assertForbidden();
    }

    public function test_inventory_actor_gets_warehouse_cards_without_finance_or_sales_leakage(): void
    {
        $user = $this->userWithGlobalRole('INVENTORY', 'ar');

        $response = $this->actingAs($user)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'warehouse']));

        $response
            ->assertOk()
            ->assertSee('مساحات عمل المؤسسة')
            ->assertSee('الكتالوج والمخزون')
            ->assertSee('مركز الإجراءات')
            ->assertDontSee('تقارير المالية')
            ->assertDontSee('عمليات الطلبات')
            ->assertSee('dir="rtl"', false);
    }

    public function test_disabled_optional_module_is_filtered_before_entitlement_queries(): void
    {
        $user = $this->userWithGlobalRole('FINANCE', 'en');

        $cards = (array) config('enterprise_workspaces.workspaces.finance.cards', []);
        $cards[] = [
            'key' => 'optional_module',
            'label' => 'enterprise_workspaces.cards.reports.title',
            'description' => 'enterprise_workspaces.cards.reports.description',
            'route' => 'admin.reports.index',
            'permission' => 'reports.view',
            'module' => 'sample_optional',
        ];
        config()->set('enterprise_workspaces.workspaces.finance.cards', $cards);

        app(ModuleRegistry::class)->syncManifest('sample_optional');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        $workspace = app(EnterpriseWorkspaceComposer::class)->compose($user, 'finance');

        $this->assertNotNull($workspace);
        $this->assertNotContains(
            'optional_module',
            array_column($workspace['cards'], 'key'),
        );
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_module_entitlements')
                || str_contains($sql, 'enterprise_store_scopes'),
        ));
    }

    public function test_super_admin_executive_workspace_uses_only_canonical_links(): void
    {
        $user = $this->userWithGlobalRole('SUPER_ADMIN', 'en');

        $workspace = app(EnterpriseWorkspaceComposer::class)->compose($user, 'executive');

        $this->assertNotNull($workspace);
        $this->assertSame(
            ['reports', 'action_center', 'system_health'],
            array_column($workspace['cards'], 'key'),
        );

        foreach ($workspace['cards'] as $card) {
            $this->assertStringStartsWith(url('/admin/'), $card['url']);
        }
    }

    private function userWithGlobalRole(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-enterprise-workspace@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
