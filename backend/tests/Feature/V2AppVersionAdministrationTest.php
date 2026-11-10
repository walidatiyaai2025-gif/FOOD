<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AppVersionAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_authorized_platform_actor_has_localized_application_tabs_and_keeps_selected_policy_context(): void
    {
        $admin = $this->actor(['SUPER_ADMIN'], 'ar');

        $this->actingAs($admin)
            ->get(route('admin.app-versions.index', ['app' => 'van', 'platform' => 'ios']))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee(route('admin.app-versions.index', ['app' => 'van', 'platform' => 'ios']), false);

        $this->post(route('admin.app-versions.store'), $this->policyPayload())
            ->assertRedirect(route('admin.app-versions.index', ['app' => 'van', 'platform' => 'ios']));

        $this->assertDatabaseHas('app_versions', [
            'app' => 'van',
            'platform' => 'ios',
            'latest_version' => '2.2.0',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'app_version_policy.updated',
            'user_id' => $admin->id,
        ]);

        $admin->forceFill(['locale' => 'en'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.app-versions.index', ['app' => 'van', 'platform' => 'ios']))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee(__('app_versions.van', [], 'en'))
            ->assertSee('2.2.0');
    }

    public function test_wholesale_and_store_scoped_roles_cannot_read_or_change_any_application_version(): void
    {
        $wholesale = $this->actor(['B2B_ADMIN']);
        $retail = $this->actor();

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-APP-VERSIONS-STORE',
            'name' => 'V2 Version Test Store',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$wholesale, $retail] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.app-versions.index', ['app' => 'van', 'platform' => 'ios']))
                ->assertForbidden();

            $this->post(route('admin.app-versions.store'), $this->policyPayload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('app_versions', 0);
    }

    public function test_platform_permission_is_effective_across_multiple_roles_and_revocation(): void
    {
        $actor = $this->actor(['B2B_ADMIN', 'SYSTEM_ADMINISTRATOR']);

        $this->actingAs($actor)
            ->get(route('admin.app-versions.index'))
            ->assertOk();

        $actor->roles()->detach(Role::query()->where('code', 'SYSTEM_ADMINISTRATOR')->firstOrFail()->id);

        $this->actingAs($actor->fresh())
            ->get(route('admin.app-versions.index'))
            ->assertForbidden();
    }

    public function test_disabled_optional_module_does_not_bypass_canonical_platform_permission(): void
    {
        $modules = app(ModuleRegistry::class);
        $registration = $modules->syncManifest('smart_ordering');
        $registration->forceFill(['state' => ModuleState::DISABLED->value])->save();

        $this->assertFalse($modules->isEnabled('smart_ordering'));
        $this->actingAs($this->actor(['SUPER_ADMIN']))
            ->get(route('admin.app-versions.index', ['app' => 'customer']))
            ->assertOk();

        $this->actingAs($this->actor(['B2B_ADMIN']))
            ->get(route('admin.app-versions.index', ['app' => 'customer']))
            ->assertForbidden();
    }

    /** @param list<string> $roles */
    private function actor(array $roles = [], string $locale = 'en'): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);

        foreach ($roles as $code) {
            $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function policyPayload(): array
    {
        return [
            'app' => 'van',
            'platform' => 'ios',
            'latest_version' => '2.2.0',
            'minimum_supported_version' => '2.0.0',
            'store_url' => 'https://apps.apple.com/app/id123456789',
            'release_notes' => 'V2 scoped test',
        ];
    }
}
