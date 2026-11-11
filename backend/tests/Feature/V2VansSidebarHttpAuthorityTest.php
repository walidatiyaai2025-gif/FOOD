<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P263: a Vans Sidebar destination is valid only when the same actor may
 * read the real fleet registry at the HTTP boundary.
 */
final class V2VansSidebarHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_wholesale_reader_can_discover_and_open_real_vans_registry_in_both_locales(): void
    {
        $actor = $this->actor('P263_VANS_READER', ['drivers.b2b.view']);
        $van = Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'P263-VAN-REGISTRY',
            'status' => 'active',
        ]);

        $this->assertTrue(Route::has('admin.field-operations.vans'));
        $this->assertTrue(Route::has('admin.field-operations.vans.show'));
        $this->assertTrue($actor->hasPermission('drivers.b2b.view'));
        $this->assertFalse($actor->hasPermission('drivers.b2b.manage'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $item = $this->vansItem($actor->fresh());

            $this->assertNotNull($item, 'P263 Vans must be discoverable in '.$locale);
            $this->assertSame('admin.field-operations.vans', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertSame(
                __('admin.field_operations.vans', [], $locale),
                __($item['label'], [], $locale),
            );

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], [], false))
                ->assertOk()
                ->assertViewHas('section', 'vans')
                ->assertViewHas('navContext', 'field_ops_vans')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($van->code);
        }

        // A read grant is not authority to register a vehicle.
        $this->actingAs($actor->fresh())
            ->post(route('admin.field-operations.vans.store', [], false), ['code' => 'P263-BLOCKED'])
            ->assertForbidden();
        $this->assertDatabaseMissing('vans', ['code' => 'P263-BLOCKED']);
    }

    public function test_other_operations_permissions_do_not_grant_vans_registry_access(): void
    {
        foreach ([
            ['P263_TRACKING_ONLY', ['drivers.tracking.view']],
            ['P263_CUSTOMERS_ONLY', ['customers.view']],
            ['P263_ROLELESS', []],
        ] as [$code, $permissions]) {
            $actor = $this->actor($code, $permissions);
            $this->assertNull($this->vansItem($actor));
            $this->actingAs($actor)
                ->get(route('admin.field-operations.vans', [], false))
                ->assertForbidden();
        }
    }

    public function test_multi_role_grant_and_global_explicit_deny_are_consistent_with_http(): void
    {
        $actor = $this->actor('P263_FLEET_READER', ['drivers.tracking.view']);
        $reader = $this->role('P263_SECOND_VANS_READER', ['drivers.b2b.view']);
        $actor->roles()->attach($reader->id);

        $this->assertNotNull($this->vansItem($actor->fresh()));
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans', [], false))
            ->assertOk();

        $permission = Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail();
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
        app()->forgetInstance(AdminNavigation::class);

        $this->assertFalse($actor->fresh()->hasPermission('drivers.b2b.view'));
        $this->assertNull($this->vansItem($actor->fresh()));
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans', [], false))
            ->assertForbidden();
    }

    public function test_retail_only_and_mobile_driver_cannot_open_wholesale_van_registry(): void
    {
        $retail = User::factory()->create(['is_active' => true]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P263-RETAIL-ONLY',
            'name' => 'P263 Retail Only',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mobileDriver = User::factory()->create(['is_active' => true]);
        $mobileDriver->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail()->id);

        foreach ([$retail, $mobileDriver] as $actor) {
            $this->assertNull($this->vansItem($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.vans', [], false))
                ->assertForbidden();
        }
    }

    public function test_inactive_wholesale_actor_cannot_read_vans_over_http(): void
    {
        $actor = $this->actor('P263_INACTIVE_VANS_READER', ['drivers.b2b.view']);
        $actor->forceFill(['is_active' => false])->save();

        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans', [], false))
            ->assertForbidden();
    }

    /** @param list<string> $permissionCodes */
    private function actor(string $roleCode, array $permissionCodes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($this->role($roleCode, $permissionCodes)->id);

        return $actor->fresh();
    }

    /** @param list<string> $permissionCodes */
    private function role(string $code, array $permissionCodes): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => 'P263 Synthetic Vans Sidebar RBAC',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissionCodes)->pluck('id')->all(),
        );

        return $role;
    }

    /** @return array<string, mixed>|null */
    private function vansItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'field_ops_vans') {
                    return $item;
                }
                foreach ((array) ($item['children'] ?? []) as $child) {
                    if ($child['key'] === 'field_ops_vans') {
                        return $child;
                    }
                }
            }
        }

        return null;
    }
}
