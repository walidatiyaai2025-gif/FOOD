<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RoleTreeNodeMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roles_manager_sees_authoritative_node_name_code_permissions_scope_channel_and_module_in_arabic_and_english(): void
    {
        $manager = $this->userWithRole('SUPER_ADMIN', 'p366-metadata-manager@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permissionsBefore = $role->permissions()->pluck('permissions.id')->all();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($manager)
                ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
                ->assertOk();

            $html = (string) $response->getContent();
            $this->assertStringContainsString('<html lang="'.$locale.'" dir="'.$direction.'">', $html);

            $procurement = $this->nodeHtml($html, 'erp_procurement');
            $this->assertStringContainsString(__('v2_role_tree.name', [], $locale), $procurement);
            $this->assertStringContainsString(__('v2_role_tree.code', [], $locale), $procurement);
            $this->assertStringContainsString('erp_procurement', $procurement);
            $this->assertStringContainsString('procurement.suppliers.view', $procurement);
            $this->assertStringContainsString(__('v2_role_tree.scopes.global', [], $locale), $procurement);
            $this->assertStringContainsString(__('v2_role_tree.modules.procurement', [], $locale), $procurement);
            $this->assertStringContainsString('admin.erp_center.modules.procurement.title', config('admin_sidebar.items.erp_procurement.label'));

            $retail = $this->nodeHtml($html, 'b2c_dashboard');
            $this->assertStringContainsString(__('v2_role_tree.scopes.store', [], $locale), $retail);
            $this->assertStringContainsString(__('v2_role_tree.channels.b2c', [], $locale), $retail);
            $this->assertStringContainsString(__('v2_role_tree.permissions_unspecified', [], $locale), $retail);

            $wholesale = $this->nodeHtml($html, 'b2b_dashboard');
            $this->assertStringContainsString(__('v2_role_tree.channels.b2b', [], $locale), $wholesale);
            $this->assertStringContainsString(__('v2_role_tree.module_unspecified', [], $locale), $wholesale);

            $catalog = $this->nodeHtml($html, 'catalog_management');
            $this->assertStringContainsString('catalog.view', $catalog);
            $this->assertStringContainsString(__('v2_role_tree.channels.unspecified', [], $locale), $catalog);
            $this->assertStringNotContainsString('v2_role_tree.', $html);
        }

        $this->assertEqualsCanonicalizing(
            $permissionsBefore,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    public function test_metadata_page_remains_forbidden_without_roles_manage_and_cannot_expose_role_data(): void
    {
        $unprivileged = User::query()->create([
            'name' => 'P366 Restricted',
            'email' => 'p366-restricted@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($unprivileged)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
            ->assertForbidden();
    }

    private function nodeHtml(string $html, string $key): string
    {
        $pattern = '/<article\\b[^>]*data-v2-role-node="'.preg_quote($key, '/').'"[^>]*>(.*?)<\\/article>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing role tree node '.$key);

        return $matches[1];
    }

    private function userWithRole(string $code, string $email): User
    {
        $user = User::query()->create([
            'name' => $code,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
