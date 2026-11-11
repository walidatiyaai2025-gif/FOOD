<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P345: the module registry is a real authorized Settings Center
 * destination, not an independent Sidebar visibility or entitlement grant.
 */
final class V2ModuleRegistrySidebarAuthorizedContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_admin_can_reach_canonical_registry_from_settings_in_both_locales(): void
    {
        $actor = $this->userWithRoles(['SUPER_ADMIN']);

        foreach (['en' => ['ltr', 'Module Control Plane'], 'ar' => ['rtl', 'إدارة وحدات المنصة']] as $locale => [$direction, $title]) {
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->actingAs($actor)
                ->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee('data-setting="enterprise_modules"', false)
                ->assertSee(route('admin.enterprise-modules.index'), false);

            $this->actingAs($actor)
                ->get(route('admin.enterprise-modules.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-mobile-web-page="enterprise-modules"', false)
                ->assertSee('data-enterprise-module-control-plane="true"', false)
                ->assertSee($title)
                ->assertSee('Sample Optional');

            $this->actingAs($actor)
                ->get(route('admin.enterprise-modules.show', ['module' => 'sample_optional']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($title)
                ->assertSee('data-module-readiness="inactive"', false);
        }
    }

    public function test_unprivileged_and_roleless_actors_cannot_read_or_mutate_module_registry(): void
    {
        $beforeModules = DB::table('enterprise_modules')->count();
        $beforeAudit = DB::table('audit_logs')->count();

        foreach ([['FINANCE'], ['B2B_ADMIN'], []] as $roles) {
            $actor = $this->userWithRoles($roles);

            $this->actingAs($actor)
                ->get(route('admin.enterprise-modules.index'))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.enterprise-modules.show', ['module' => 'sample_optional']))
                ->assertForbidden();

            $this->actingAs($actor)
                ->post(route('admin.enterprise-modules.lifecycle', ['module' => 'sample_optional']), [
                    'action' => 'enable',
                ])
                ->assertForbidden();
        }

        $this->assertSame($beforeModules, DB::table('enterprise_modules')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_unknown_module_and_invalid_transition_do_not_create_registration_or_audit(): void
    {
        $actor = $this->userWithRoles(['SUPER_ADMIN']);
        $beforeModules = DB::table('enterprise_modules')->count();
        $beforeAudit = DB::table('audit_logs')->count();

        $this->actingAs($actor)
            ->get(route('admin.enterprise-modules.show', ['module' => 'not_registered']))
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('admin.enterprise-modules.lifecycle', ['module' => 'not_registered']), [
                'action' => 'enable',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->from(route('admin.enterprise-modules.show', ['module' => 'sample_optional']))
            ->post(route('admin.enterprise-modules.lifecycle', ['module' => 'sample_optional']), [
                'action' => 'not_a_state',
            ])
            ->assertSessionHasErrors(['action']);

        $this->assertSame($beforeModules, DB::table('enterprise_modules')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    /** @param list<string> $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ($roleCodes as $roleCode) {
            $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
        }

        return $actor;
    }
}
