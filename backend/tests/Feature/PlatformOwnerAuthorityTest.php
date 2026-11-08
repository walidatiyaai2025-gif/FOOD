<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\PlatformOwnerProvisioner;
use App\Services\RbacManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class PlatformOwnerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_only_internally_registered_owner_receives_complete_permission_and_module_inventory(): void
    {
        $role = Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail();
        $this->assertTrue($role->is_system);

        $unregistered = User::factory()->create(['is_active' => true]);
        $unregistered->roles()->attach($role->getKey());
        $this->assertFalse($unregistered->isPlatformOwner());
        $this->assertFalse($unregistered->hasRole('PLATFORM_OWNER'));
        $this->assertFalse($unregistered->hasPermission('platform.manage'));

        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'private-owner@example.test',
            'LongStrongSecret!2026#Unique',
        );
        $this->assertTrue($owner->isPlatformOwner());
        $this->assertTrue($owner->hasRole('PLATFORM_OWNER'));
        $this->assertTrue($owner->hasPermission('platform.manage'));
        $this->assertTrue($owner->hasPermission('orders.customer_service_discount'));
        $this->assertTrue($owner->hasPermission('enterprise.modules.manage'));
        $this->assertNotSame('LongStrongSecret!2026#Unique', $owner->password);
        $this->assertDatabaseHas('platform_owner_registry', [
            'id' => 1, 'user_id' => $owner->getKey(),
        ]);

        $groups = app(AdminNavigation::class)->groupsFor($owner);
        $keys = collect($groups)->flatMap(fn (array $group) => $group['children'])
            ->pluck('key')->all();
        $this->assertContains('platform_owner_modules', $keys);
        $this->assertContains('b2c_dashboard', $keys);

        $this->expectException(ValidationException::class);
        app(PlatformOwnerProvisioner::class)->provision(
            'second-owner@example.test', 'AnotherStrong!OwnerSecret2026',
        );
    }

    public function test_super_admin_cannot_grant_owner_role_via_role_management(): void
    {
        $super = User::factory()->create(['is_active' => true]);
        $super->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $target = User::factory()->create(['is_active' => true]);
        $ownerRole = Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail();

        $this->assertFalse($super->isPlatformOwner());
        $this->expectException(HttpException::class);
        app(RbacManager::class)->replaceUserRoles(
            $super, $target, [(int) $ownerRole->getKey()], [], Request::create('/admin', 'POST'),
        );
    }

    public function test_normal_rbac_cannot_deactivate_or_replace_registered_owner(): void
    {
        $super = User::factory()->create(['is_active' => true]);
        $super->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'locked-owner@example.test', 'StrongSecureOwnerPassword!26',
        );

        try {
            app(RbacManager::class)->setUserActive(
                $super, $owner, false, 'Unapproved', Request::create('/admin', 'PATCH'),
            );
            $this->fail('Deactivating a registered Platform Owner must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            app(RbacManager::class)->replaceUserRoles(
                $super, $owner, [], [], Request::create('/admin', 'POST'),
            );
            $this->fail('Changing owner roles must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($owner->fresh()->isPlatformOwner());
        $this->assertSame(1, DB::table('platform_owner_registry')->count());
    }
}
