<?php

namespace Tests\Feature;

use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseOrganizationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_build_hierarchy_and_assign_branch_through_authoritative_scope_service(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'org-owner@example.test');

        $this->actingAs($admin)->post(route('admin.enterprise-organizations.store'), [
            'code' => 'north_group', 'name' => 'North Group', 'is_active' => 1,
        ])->assertRedirect();

        $organization = EnterpriseOrganization::query()->where('code', 'north_group')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.enterprise-companies.store', $organization), [
            'code' => 'north_company', 'name' => 'North Company', 'is_active' => 1,
        ])->assertRedirect();

        $company = EnterpriseCompany::query()->where('code', 'north_company')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.enterprise-legal-entities.store', $company), [
            'code' => 'north_legal', 'name' => 'North Legal Entity', 'is_active' => 1,
        ])->assertRedirect();

        $entity = EnterpriseLegalEntity::query()->where('code', 'north_legal')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.enterprise-business-units.store', $entity), [
            'code' => 'north_ops', 'name' => 'North Operations', 'is_active' => 1,
        ])->assertRedirect();

        $unit = EnterpriseBusinessUnit::query()->where('code', 'north_ops')->firstOrFail();
        $store = $this->store('ORG-ADMIN-BRANCH');

        // A new store starts without an Enterprise scope, so assignment inherits the selected hierarchy.
        $this->actingAs($admin)->post(route('admin.enterprise-business-units.stores.assign', [
            'businessUnit' => $unit, 'store' => $store,
        ]))->assertRedirect();

        $this->assertDatabaseHas('enterprise_store_scopes', [
            'store_id' => $store->id,
            'business_unit_id' => $unit->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.organization.store_scope_assigned']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.business_unit.created']);
    }

    public function test_parent_with_active_children_cannot_be_deactivated(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'org-guard@example.test');
        $organization = EnterpriseOrganization::query()->where('code', 'foodex_platform')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.enterprise-organizations.update', $organization), [
            'code' => $organization->code,
            'name' => $organization->name,
            'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $organization->fresh()->is_active);
    }

    public function test_non_platform_admin_is_denied_and_locales_render_in_correct_direction(): void
    {
        $finance = $this->userWithRole('FINANCE', 'en', 'org-finance@example.test');
        $this->actingAs($finance)->get(route('admin.enterprise-organizations.index'))->assertForbidden();

        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'org-locale@example.test');
        $this->actingAs($admin)->get(route('admin.enterprise-organizations.index'))
            ->assertOk()->assertSee('dir="ltr"', false)->assertSee('Organization Administration');

        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin->fresh())->get(route('admin.enterprise-organizations.index'))
            ->assertOk()->assertSee('dir="rtl"', false)->assertSee('إدارة الهيكل المؤسسي');
    }

    private function userWithRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function store(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->whereNotNull('id')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
                'code' => 'ORG-ADMIN', 'name' => 'Organization Admin', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => 'Organization Admin Branch',
            'is_active' => true,
        ]);
    }
}
