<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_workspace_is_permission_aware_and_uses_canonical_destinations(): void
    {
        $actor = $this->userWithPermissions([
            'customers.view',
            'orders.view',
            'finance.view',
            'field_ops.manage',
            'whatsapp.inbox.view',
        ]);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertViewIs('admin.customer-service-workspace')
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertSee(route('admin.operations.orders.index'), false)
            ->assertSee(route('admin.field-operations.overview'), false)
            ->assertSee(route('admin.whatsapp-inbox.index'), false)
            ->assertSee(__('customer_service_workspace.truth_title'))
            ->assertSee('name="q"', false)
            ->assertDontSee('name="customer_id"', false);
    }

    public function test_customer_only_role_does_not_leak_unpermitted_operational_destinations(): void
    {
        $actor = $this->userWithPermissions(['customers.view']);

        $response = $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertViewHas('capabilities', function ($capabilities): bool {
                $keys = $capabilities->pluck('key');

                return $keys->contains('customer')
                    && $keys->contains('activity')
                    && ! $keys->contains('delivery')
                    && ! $keys->contains('conversations');
            });

        $response->assertDontSee(route('admin.whatsapp-inbox.index'), false);
    }

    public function test_user_without_customer_service_permissions_is_denied(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertForbidden();
    }

    public function test_workspace_has_arabic_and_english_translation_parity(): void
    {
        $en = require lang_path('en/customer_service_workspace.php');
        $ar = require lang_path('ar/customer_service_workspace.php');

        $this->assertSame(array_keys($en), array_keys($ar));
        $this->assertSame(array_keys($en['cards']), array_keys($ar['cards']));

        foreach (array_keys($en['cards']) as $key) {
            $this->assertSame(array_keys($en['cards'][$key]), array_keys($ar['cards'][$key]));
        }
    }

    private function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => 'CUSTOMER_SERVICE_TEST_'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Customer Service Test',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);

        $permissionIds = collect($codes)->map(function (string $code): int {
            return (int) Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            )->id;
        })->all();

        $role->permissions()->attach($permissionIds);
        $user->roles()->attach($role);

        return $user;
    }
}
