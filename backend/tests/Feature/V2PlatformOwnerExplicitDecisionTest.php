<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\PlatformOwnerProvisioner;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2PlatformOwnerExplicitDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_owner_role_assignment_without_internal_registry_never_grants_control_plane_access_in_either_locale(): void
    {
        $ownerRole = Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail();

        foreach (['ar', 'en'] as $locale) {
            $spoof = User::factory()->create(['is_active' => true, 'locale' => $locale]);
            $spoof->roles()->attach($ownerRole);

            $this->assertFalse($spoof->isPlatformOwner());
            $this->assertFalse($spoof->hasRole('PLATFORM_OWNER'));
            $this->assertFalse($spoof->hasRole('SUPER_ADMIN'));
            $this->assertFalse($spoof->hasRole('PLATFORM_OWNER', 1));
            $this->assertFalse($spoof->hasPermission('platform.manage'));
            $this->assertFalse($spoof->hasPermission('enterprise.modules.manage'));
            $this->assertFalse($spoof->hasPermission('platform.manage', 1));

            Sanctum::actingAs($spoof);
            $this->getJson('/api/v1/admin/security/permissions')->assertForbidden();
            $this->getJson('/api/v1/admin/security/roles')->assertForbidden();
        }

        $this->assertSame(0, DB::table('platform_owner_registry')->count());
    }

    public function test_internal_registered_owner_gets_explicit_global_authority_not_an_assignable_role_alias(): void
    {
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'v2-owner-authority@example.test',
            'UniqueStrongOwnerSecret!2026',
        );

        $this->assertTrue($owner->isPlatformOwner());
        $this->assertTrue($owner->hasRole('PLATFORM_OWNER'));
        $this->assertTrue($owner->hasRole('SUPER_ADMIN'));
        $this->assertTrue($owner->hasPermission('security.view'));
        $this->assertTrue($owner->hasPermission('enterprise.modules.manage'));
        $this->assertDatabaseHas('platform_owner_registry', [
            'id' => 1,
            'user_id' => $owner->getKey(),
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/security/permissions')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_super_admin_cannot_deactivate_or_reassign_the_registered_owner_through_direct_http(): void
    {
        $superAdmin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'v2-protected-owner@example.test',
            'DifferentStrongOwnerSecret!2026',
        );

        $this->assertFalse($superAdmin->isPlatformOwner());

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/v1/admin/security/users/{$owner->id}/status", [
            'is_active' => false,
            'reason' => 'Attempted unauthorized owner offboarding',
        ])->assertForbidden();

        $this->putJson("/api/v1/admin/security/users/{$owner->id}/roles", [
            'global_role_ids' => [],
            'store_roles' => [],
        ])->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertTrue($owner->fresh()->isPlatformOwner());
        $this->assertSame(1, DB::table('platform_owner_registry')->count());
    }
}
