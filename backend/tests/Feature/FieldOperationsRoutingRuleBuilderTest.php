<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoutingPolicy;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\RoutingPolicyService;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FieldOperationsRoutingRuleBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_standard_builder_enforces_authorized_store_scope_and_active_lookup_values(): void
    {
        $allowed = $this->b2bStore('ROUTING-ALLOWED');
        $foreign = $this->b2bStore('ROUTING-FOREIGN');
        $actor = $this->scopedFieldOperationsUser($allowed);
        $van = app(VanRegistryService::class)->createVan(['code' => 'VAN-ROUTING-SCOPE']);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'scoped_valid',
                'mode' => 'AUTOMATIC',
                'rules' => [[
                    'name' => 'Allowed store',
                    'condition_key' => 'store_id',
                    'condition_value' => (string) $allowed->id,
                    'action_key' => 'van_id',
                    'action_value' => (string) $van->id,
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $policy = RoutingPolicy::query()->where('code', 'scoped_valid')->with('rules')->firstOrFail();
        $rule = $policy->rules->firstOrFail();
        $this->assertSame(['store_id' => $allowed->id], $rule->conditions);
        $this->assertSame(['van_id' => $van->id], $rule->actions);

        $this->actingAs($actor)
            ->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'scoped_foreign',
                'mode' => 'AUTOMATIC',
                'rules' => [[
                    'name' => 'Foreign store',
                    'condition_key' => 'store_id',
                    'condition_value' => (string) $foreign->id,
                    'action_key' => 'manual_dispatch_required',
                    'action_value' => '1',
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasErrors('rules');

        $this->assertDatabaseMissing('routing_policies', ['code' => 'scoped_foreign']);

        $van->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($actor)
            ->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'inactive_van',
                'mode' => 'AUTOMATIC',
                'rules' => [[
                    'name' => 'Inactive Van',
                    'condition_key' => 'store_id',
                    'condition_value' => (string) $allowed->id,
                    'action_key' => 'van_id',
                    'action_value' => (string) $van->id,
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasErrors('rules');

        $this->assertDatabaseMissing('routing_policies', ['code' => 'inactive_van']);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'coordinate_valid',
                'mode' => 'AUTOMATIC',
                'rules' => [[
                    'name' => 'Latitude condition',
                    'condition_key' => 'latitude',
                    'condition_value' => '29.375900',
                    'action_key' => 'manual_dispatch_required',
                    'action_value' => '1',
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $coordinateRule = RoutingPolicy::query()
            ->where('code', 'coordinate_valid')
            ->with('rules')
            ->firstOrFail()
            ->rules
            ->firstOrFail();
        $this->assertSame(['latitude' => 29.3759], $coordinateRule->conditions);

        $this->actingAs($actor)
            ->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'coordinate_invalid',
                'mode' => 'AUTOMATIC',
                'rules' => [[
                    'name' => 'Invalid latitude',
                    'condition_key' => 'latitude',
                    'condition_value' => '91',
                    'action_key' => 'manual_dispatch_required',
                    'action_value' => '1',
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasErrors('rules');

        $this->assertDatabaseMissing('routing_policies', ['code' => 'coordinate_invalid']);
    }

    public function test_routing_page_keeps_legacy_rules_visible_and_normal_flow_key_free(): void
    {
        $actor = $this->superAdmin();
        $store = $this->b2bStore('ROUTING-PAGE');
        $van = app(VanRegistryService::class)->createVan(['code' => 'VAN-ROUTING-PAGE']);

        app(RoutingPolicyService::class)->createDraft($actor, 'legacy_policy', 'AUTOMATIC', [[
            'name' => 'Legacy rule',
            'conditions' => ['legacy_condition' => 'legacy-value'],
            'actions' => ['legacy_action' => 'legacy-result'],
            'enabled' => true,
        ]]);

        $this->actingAs($actor)
            ->get(route('admin.field-operations.routing'))
            ->assertOk()
            ->assertSee($store->name)
            ->assertSee($van->code)
            ->assertSee(__('field_operations.legacy_routing_field', ['field' => 'legacy_condition']))
            ->assertSee(__('field_operations.legacy_routing_field', ['field' => 'legacy_action']))
            ->assertSee(__('field_operations.legacy_routing_help'))
            ->assertSee(__('field_operations.create_legacy_replacement'))
            ->assertSee('data-routing-existing-rules', false)
            ->assertSee('data-routing-legacy-replacement', false)
            ->assertSee('data-routing-order', false)
            ->assertSee('data-routing-number-value="condition"', false)
            ->assertSee('data-routing-number-value="simulation"', false)
            ->assertDontSee('<input name="rules[0][condition_key]"', false)
            ->assertDontSee('<input name="rules[0][action_key]"', false)
            ->assertDontSee('<input name="input_keys[]"', false)
            ->assertDontSee('<input name="scope_keys[]"', false);
    }

    public function test_simulation_uses_scoped_typed_values_and_returns_readable_result_state(): void
    {
        $actor = $this->superAdmin();
        $store = $this->b2bStore('ROUTING-SIM');
        $policy = app(RoutingPolicyService::class)->createDraft($actor, 'simulation_policy', 'AUTOMATIC', [[
            'name' => 'Store match',
            'conditions' => ['store_id' => $store->id],
            'actions' => ['manual_dispatch_required' => true],
            'enabled' => true,
        ]]);

        $response = $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.action', [
                'routingPolicy' => $policy,
                'action' => 'simulate',
            ]), [
                'input_keys' => ['store_id'],
                'input_values' => [(string) $store->id],
                'scope_keys' => ['channel'],
                'scope_values' => ['b2b'],
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('simulation_result', function (array $result): bool {
            return ($result['result']['manual_dispatch_required'] ?? null) === true
                && count($result['matched_rules'] ?? []) === 1;
        });

        $this->actingAs($actor)
            ->get(route('admin.field-operations.routing'))
            ->assertOk()
            ->assertSee(__('field_operations.matched_rules'))
            ->assertSee(__('field_operations.routing_result'));
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function scopedFieldOperationsUser(Store $store): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'code' => 'TEST_FIELD_ROUTING',
            'name' => 'Test Field Routing',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', 'field_ops.manage')->firstOrFail(),
        );
        $user->roles()->attach($role);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function b2bStore(string $suffix): Store
    {
        $type = StoreType::query()->firstOrCreate(
            ['code' => 'B2B'],
            ['name' => 'Wholesale'],
        );

        return Store::query()->create([
            'store_type_id' => $type->id,
            'code' => 'STORE-'.$suffix,
            'name' => 'Store '.$suffix,
            'is_active' => true,
        ]);
    }
}
