<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V2 T122R22: Van Operator is scoped to the assigned Van app, never
 * Dashboard, Driver, Finance or an arbitrary Van vehicle.
 */
final class V2VanOperatorAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_builtin_role_has_only_van_login_and_authoritative_ar_en_presentation(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('VAN_OPERATOR');

        $this->assertIsArray($definition);
        $this->assertSame('field_operations', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertSame(['van.login'], $definition['permissions']);

        $role = Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertSame(['van.login'], $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مشغل الفان', 'en' => 'Van Operator'] as $locale => $expected) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('VAN_OPERATOR');

            $this->assertSame($expected, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_operator_needs_real_effective_assignment_and_only_the_assigned_van_can_be_selected(): void
    {
        $operator = $this->operator('v2-2702-assignment@example.test');

        $this->vanLogin($operator)->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $operator->id,
        ]);

        [$van, $assignment] = $this->assign($operator, 'V2-2702-OWN');
        $otherVan = app(VanRegistryService::class)->createVan(['code' => 'V2-2702-FOREIGN']);

        $this->vanLogin($operator, ['van_id' => $otherVan->id])->assertForbidden();

        $login = $this->vanLogin($operator, ['van_id' => $van->id])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.van_scope.van_id', $van->id)
            ->assertJsonPath('user.van_scope.assignment_id', $assignment->id)
            ->assertJsonPath('user.van_scope.van_code', 'V2-2702-OWN');

        $this->assertContains('van.login', $login->json('user.permissions'));
        $this->assertNotContains('deliveries.b2c.execute', $login->json('user.permissions'));
        $this->assertNotContains('orders.dispatch', $login->json('user.permissions'));
        $this->assertNotContains('finance.post', $login->json('user.permissions'));

        $token = (string) $login->json('token');
        $this->withToken($token)->getJson('/api/v1/van/no-order-reasons')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $operator->id,
            'name' => 'foodex-van',
            'abilities' => json_encode(['app:van'], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_assignment_does_not_create_permission_and_revoke_fails_closed_at_login_and_api(): void
    {
        $roleless = $this->operator('v2-2702-roleless@example.test', false);
        $this->assign($roleless, 'V2-2702-ROLELESS');

        $this->vanLogin($roleless)->assertForbidden();

        $operator = $this->operator('v2-2702-revoke@example.test');
        [, $assignment] = $this->assign($operator, 'V2-2702-REVOKE');

        $token = (string) $this->vanLogin($operator)->assertOk()->json('token');
        DB::table('van_assignments')->where('id', $assignment->id)
            ->update(['status' => 'ended', 'updated_at' => now()]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/van/no-order-reasons')->assertForbidden();
        $this->vanLogin($operator)->assertForbidden();

        $reassigned = $this->operator('v2-2702-permission-revoke@example.test');
        $this->assign($reassigned, 'V2-2702-PERMISSION-REVOKE');
        $activeToken = (string) $this->vanLogin($reassigned)->assertOk()->json('token');
        $reassigned->roles()->detach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail()->id);

        $this->app['auth']->forgetGuards();
        $this->withToken($activeToken)->getJson('/api/v1/van/no-order-reasons')->assertForbidden();
        $this->vanLogin($reassigned)->assertForbidden();
    }

    public function test_explicit_global_deny_and_inactive_account_block_van_login_without_leaking_token(): void
    {
        $operator = $this->operator('v2-2702-deny@example.test');
        $this->assign($operator, 'V2-2702-DENY');

        $permission = Permission::query()->where('code', 'van.login')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $operator->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operator->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($operator->fresh()->hasPermission('van.login'));
        $this->vanLogin($operator)->assertForbidden();

        $inactive = $this->operator('v2-2702-inactive@example.test');
        $this->assign($inactive, 'V2-2702-INACTIVE');
        $inactive->forceFill(['is_active' => false])->save();

        $this->vanLogin($inactive)->assertUnprocessable();
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', [$operator->id, $inactive->id])
            ->count());
    }

    private function operator(string $email, bool $assignRole = true): User
    {
        $user = User::query()->create([
            'name' => 'V2 Van Operator 2702',
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);

        if ($assignRole) {
            $user->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());
        }

        return $user->fresh();
    }

    /** @return array{0: Van, 1: VanAssignment} */
    private function assign(User $operator, string $code): array
    {
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => $code]);
        $assignment = $registry->assign($operator, $van, [
            'representative_user_id' => $operator->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        return [$van, $assignment];
    }

    /** @param array<string,mixed> $extra */
    private function vanLogin(User $operator, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $operator->email,
            'password' => 'correct-password',
            'app' => 'van',
            ...$extra,
        ]);
    }
}
