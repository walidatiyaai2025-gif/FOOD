<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P080: one canonical role registry, multiple independent assignments and
 * advisory SoD warnings. Sidebar previews cannot grant access.
 */
final class V2MultiRoleRegistryConflictContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_registry_and_advisory_conflict_json_reach_browser_without_double_html_escaping_in_both_locales(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $pairs = (array) config('job_roles.risk_pairs');
        $this->assertSame(
            ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
            $pairs['procurement_maker_checker']['roles'],
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin)
                ->get(route('admin.security.index', ['section' => 'users', 'q' => $target->email]));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('name="global_role_ids[]"', false)
                ->assertSee('data-role-effective-preview', false)
                ->assertSee('data-role-risk-warning', false)
                ->assertSee('data-v2-user-page-tree', false);

            $html = $response->getContent();
            $this->assertIsString($html);

            $decodedPairs = $this->attributeJson($html, 'data-role-risk-pairs');
            $this->assertSame($pairs, $decodedPairs);

            $decodedWarnings = $this->attributeJson($html, 'data-role-risk-messages');
            $this->assertSame(
                __('job_roles.risk_warnings.procurement_maker_checker'),
                $decodedWarnings['procurement_maker_checker'],
            );

            $roleSummary = $this->attributeJson($html, 'data-role-permission-summary');
            $this->assertIsArray($roleSummary);
        }
    }

    public function test_multiple_global_and_single_store_role_assignments_persist_atomically_and_preserve_scope_and_audit(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $catalog = $this->customRole('P080_CATALOG', 'global', 'catalog.view');
        $customers = $this->customRole('P080_CUSTOMERS', 'global', 'customers.view');
        $inventory = $this->customRole('P080_STORE_INVENTORY', 'store', 'inventory.view');
        $storeOne = $this->store('B2C', 'P080-RETAIL-1');
        $storeTwo = $this->store('B2C', 'P080-RETAIL-2');

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$catalog->id, $customers->id],
                'store_role_ids' => [$storeOne => [$inventory->id]],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $catalog->id]);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $customers->id]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'role_id' => $inventory->id,
            'store_id' => $storeOne,
        ]);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $target->id,
            'role_id' => $inventory->id,
            'store_id' => $storeTwo,
        ]);
        $this->assertSame(2, $target->fresh()->roles()->count());
        $this->assertTrue($target->fresh()->hasPermission('catalog.view'));
        $this->assertTrue($target->fresh()->hasPermission('customers.view'));
        $this->assertTrue($target->fresh()->hasPermission('inventory.view', $storeOne));
        $this->assertFalse($target->fresh()->hasPermission('inventory.view', $storeTwo));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.roles_changed',
        ]);
    }

    public function test_invalid_second_store_scope_cannot_partially_save_first_role_and_roleless_actor_cannot_escalate(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $catalog = $this->customRole('P080_VALID_GLOBAL', 'global', 'catalog.view');
        $inventory = $this->customRole('P080_INVALID_B2B_STORE', 'store', 'inventory.view');
        $wholesale = $this->store('B2B', 'P080-WHOLESALE');

        $this->actingAs($admin)
            ->from(route('admin.security.index', ['section' => 'users']))
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$catalog->id],
                'store_role_ids' => [$wholesale => [$inventory->id]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('store_roles');

        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id, 'role_id' => $catalog->id]);
        $this->assertSame(0, $target->fresh()->storeRoleAssignments()->count());

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$catalog->id],
            ])
            ->assertForbidden();

        $this->assertSame(0, $target->fresh()->roles()->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'user.roles_changed')->count());
    }

    /** @return array<mixed> */
    private function attributeJson(string $html, string $attribute): array
    {
        $matched = preg_match('/'.preg_quote($attribute, '/').'="([^"]*)"/u', $html, $matches);
        $this->assertSame(1, $matched, 'Missing browser data attribute: '.$attribute);
        $this->assertStringNotContainsString('&amp;quot;', $matches[1]);

        // The browser decodes entities exactly once when exposing dataset.
        $browserValue = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($browserValue, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $user;
    }

    private function customRole(string $code, string $scope, string $permissionCode): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', $permissionCode)->firstOrFail()->id);

        return $role;
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
