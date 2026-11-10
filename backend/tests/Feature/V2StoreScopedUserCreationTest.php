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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class V2StoreScopedUserCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_super_admin_creates_retail_user_without_unwanted_global_permissions(): void
    {
        $store = $this->store('B2C', 'V2-USER-RETAIL');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $admin = $this->actor('SUPER_ADMIN', 'ar');

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertSee('data-security-create-user', false)
            ->assertSee(__('admin.security.initial_store_role'))
            ->assertSee($store->id.':'.$role->id);

        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin->fresh())
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee(__('admin.security.initial_store_role', [], 'en'));

        $this->post(route('admin.security.users.store'), [
            ...$this->payload('store.user@example.test'),
            'store_role_assignment' => $store->id.':'.$role->id,
        ])->assertRedirect(route('admin.security.index', ['section' => 'users']));

        $target = User::query()->where('email', 'store.user@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePass!2026', $target->password));
        $this->assertSame(0, $target->roles()->count());
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.created',
            'user_id' => $admin->id,
            'auditable_id' => $target->id,
        ]);

        $this->actingAs($target)->get(route('admin.security.index'))->assertForbidden();
    }

    public function test_cannot_assign_retail_role_to_wholesale_store_and_transaction_rolls_back(): void
    {
        $store = $this->store('B2B', 'V2-USER-WHOLESALE');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $this->actingAs($this->actor('SUPER_ADMIN', 'en'))
            ->from(route('admin.security.index', ['section' => 'users']))
            ->post(route('admin.security.users.store'), [
                ...$this->payload('forbidden.store@example.test'),
                'store_role_assignment' => $store->id.':'.$role->id,
            ])
            ->assertRedirect(route('admin.security.index', ['section' => 'users']))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('users', ['email' => 'forbidden.store@example.test']);
    }

    public function test_missing_or_malformed_role_assignment_never_creates_user(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN', 'en'));

        $this->from(route('admin.security.index', ['section' => 'users']))
            ->post(route('admin.security.users.store'), $this->payload('no.roles@example.test'))
            ->assertSessionHasErrors('role_id');

        $this->from(route('admin.security.index', ['section' => 'users']))
            ->post(route('admin.security.users.store'), [
                ...$this->payload('bad.store@example.test'),
                'store_role_assignment' => '../2:1',
            ])->assertSessionHasErrors('store_role_assignment');

        $this->assertDatabaseMissing('users', ['email' => 'no.roles@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'bad.store@example.test']);
    }

    public function test_non_authorized_wholesale_user_cannot_create_store_scoped_user(): void
    {
        $store = $this->store('B2C', 'V2-STORE-FORBIDDEN');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $this->actingAs($this->actor('B2B_ADMIN'))
            ->post(route('admin.security.users.store'), [
                ...$this->payload('cross.store@example.test'),
                'store_role_assignment' => $store->id.':'.$role->id,
            ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'cross.store@example.test']);
    }

    public function test_disabled_optional_module_does_not_permit_unscoped_user_creation(): void
    {
        $module = app(ModuleRegistry::class)->syncManifest('smart_ordering');
        $module->forceFill(['state' => ModuleState::DISABLED->value])->save();

        $this->actingAs($this->actor('B2B_ADMIN'))
            ->post(route('admin.security.users.store'), $this->payload('no.module.bypass@example.test'))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'no.module.bypass@example.test']);
    }

    private function actor(string $code, string $locale = 'en'): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }

    private function store(string $typeCode, string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', $typeCode)->value('id'),
            'code' => $code,
            'name' => 'V2 Store '.$code,
            'is_active' => true,
        ]);
    }

    /** @return array<string, string|int> */
    private function payload(string $email): array
    {
        return [
            'name' => 'V2 Scoped User',
            'username' => '',
            'email' => $email,
            'password' => 'SecurePass!2026',
            'password_confirmation' => 'SecurePass!2026',
            'locale' => 'ar',
            'role_id' => '',
            'is_active' => 1,
        ];
    }
}
