<?php

namespace Tests\Feature;

use App\Models\EnterpriseApprovalLimit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2CustomRoleDetailsContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_custom_role_details_are_saved_read_only_and_localized_in_both_directions(): void
    {
        $actor = $this->globalActor(['security.view']);
        $role = Role::query()->create([
            'code' => 'V2_P098_CUSTOM',
            'name' => 'P098 custom finance role',
            'description' => 'Saved author-approved role description',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'catalog.view')->firstOrFail());

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-P098-STORE',
            'name' => 'P098 Synthetic Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignee = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $assignee->id,
            'role_id' => $role->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        EnterpriseApprovalLimit::query()->create([
            'role_id' => $role->id,
            'permission_code' => 'finance.approve',
            'scope_type' => 'store',
            'scope_id' => $storeId,
            'scope_key' => 'store:'.$storeId,
            'currency' => 'EGP',
            'max_amount' => '12500.000',
            'is_active' => true,
        ]);
        EnterpriseApprovalLimit::query()->create([
            'role_id' => $role->id,
            'permission_code' => 'finance.post',
            'scope_type' => 'global',
            'scope_id' => null,
            'scope_key' => 'global',
            'currency' => 'KWD',
            'max_amount' => '99999.000',
            'is_active' => false,
        ]);

        $roleCount = Role::query()->count();
        $limitCount = EnterpriseApprovalLimit::query()->count();
        foreach (['ar' => ['rtl', 'تفاصيل الدور المخصص'], 'en' => ['ltr', 'Custom role details']] as $locale => [$direction, $heading]) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())->get('/admin/security?section=roles');
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-custom-role-details="'.$role->id.'"', false)
                ->assertSee($heading)
                ->assertSee('V2_P098_CUSTOM')
                ->assertSee('Saved author-approved role description')
                ->assertSee('data-v2-custom-role-assigned-stores', false)
                ->assertSee('P098 Synthetic Store')
                ->assertSee('catalog.view')
                ->assertSee('12500.000')
                ->assertSee('EGP')
                ->assertDontSee('99999.000');
        }

        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($limitCount, EnterpriseApprovalLimit::query()->count());
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $assignee->id,
            'role_id' => $role->id,
            'store_id' => $storeId,
        ]);
        $this->assertFalse($actor->fresh()->hasPermission('roles.manage'));
        $this->actingAs($actor->fresh())
            ->post('/admin/security/roles', [])
            ->assertForbidden();
    }

    public function test_role_details_do_not_bypass_global_role_security_or_inactive_role_status(): void
    {
        $restricted = User::factory()->create(['is_active' => true]);
        $this->actingAs($restricted)
            ->get('/admin/security?section=roles')
            ->assertForbidden();

        $storeRole = Role::query()->create([
            'code' => 'V2_P098_STORE_VIEW',
            'name' => 'Store-only role reader',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $storeRole->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail());
        $storeActor = User::factory()->create(['is_active' => true]);
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-P098-RESTRICTED',
            'name' => 'P098 restricted store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $storeActor->id,
            'role_id' => $storeRole->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue($storeActor->fresh()->hasPermission('security.view', $storeId));
        $this->assertFalse($storeActor->fresh()->hasPermission('security.view'));
        $this->actingAs($storeActor->fresh())
            ->get('/admin/security?section=roles')
            ->assertForbidden();

        $inactive = $this->globalActor(['security.view'], false);
        $this->actingAs($inactive)
            ->get('/admin/security?section=roles')
            ->assertForbidden();
        Sanctum::actingAs($inactive, ['*']);
        $this->getJson('/api/v1/admin/security/roles')->assertForbidden();
    }

    public function test_authorized_read_api_exposes_role_configuration_without_granting_mutation(): void
    {
        $actor = $this->globalActor(['security.view']);
        $role = Role::query()->create([
            'code' => 'V2_P098_API',
            'name' => 'P098 API role',
            'description' => 'Saved scoped API role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor, ['*']);
        $this->getJson('/api/v1/admin/security/roles')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'V2_P098_API',
                'description' => 'Saved scoped API role',
                'scope' => 'global',
            ]);
        $this->patchJson('/api/v1/admin/security/roles/'.$role->id, [])->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function globalActor(array $permissions, bool $activeRole = true): User
    {
        $role = Role::query()->create([
            'code' => 'V2_P098_VIEW_'.Role::query()->count(),
            'name' => 'P098 authorized reader',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => $activeRole,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $permissions)->pluck('id')->all());
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role);

        return $actor->fresh();
    }
}
