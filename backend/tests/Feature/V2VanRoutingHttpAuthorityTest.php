<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoutingPolicy;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\RoutingPolicyService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P271: routing is the canonical, server-authorized B2B Van field-operations
 * workflow. A visible link is never a permission to create/publish policies.
 */
final class V2VanRoutingHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_routing_navigation_and_real_page_are_authorized_in_both_locales(): void
    {
        $actor = $this->actorWithPermissions('P2601_ROUTING_ACCESS', [
            'field_ops.manage', 'reports.view',
        ]);
        $registry = app(AdminSidebarManager::class)->registry()['items']['field_ops_routing'];

        $this->assertSame('field_operations', $registry['group']);
        $this->assertSame(['territories.manage', 'field_ops.manage'], $registry['permissions']);
        $this->assertTrue(Route::has('admin.field-operations.routing'));
        $this->assertTrue(Route::has('admin.field-operations.routing.store'));
        $this->assertTrue(Route::has('admin.field-operations.routing.action'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $matches = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => collect($group['children'])
                    ->map(static fn (array $item): array => [...$item, 'group_key' => $group['key']])
                    ->all())
                ->where('key', 'field_ops_routing')
                ->values();

            $this->assertCount(1, $matches);
            $this->assertSame('field_operations', $matches->first()['group_key']);
            $this->assertSame('admin.field-operations.routing', $matches->first()['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.routing'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-item="field_ops_routing"', false)
                ->assertSee(__('admin.field_operations.routing', [], $locale));
        }
    }

    public function test_routing_permissions_do_not_allow_retail_roleless_or_inactive_actors_to_write_policies(): void
    {
        $retail = $this->retailStore('P2601-RETAIL-ONLY');
        $retailActor = User::factory()->create(['is_active' => true]);
        $retailRole = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $retailActor->getKey(),
            'store_id' => $retail->getKey(),
            'role_id' => $retailRole->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->actorWithPermissions('P2601_INACTIVE', [
            'field_ops.manage', 'reports.view',
        ]);
        $inactive->forceFill(['is_active' => false])->save();

        $admin = $this->actorWithPermissions('P2601_CREATE_POLICY', [
            'field_ops.manage', 'reports.view',
        ]);
        $policy = app(RoutingPolicyService::class)->createDraft($admin, 'P2601-protected', 'MANUAL', [[
            'name' => 'Read only B2B',
            'conditions' => ['channel' => 'b2b'],
            'actions' => ['manual_dispatch_required' => true],
            'enabled' => true,
        ]]);

        $beforePolicies = DB::table('routing_policies')->count();
        $beforeTraces = DB::table('routing_decision_traces')->count();

        foreach ([$retailActor, $roleless, $inactive] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();

                $visible = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')->all();
                $this->assertNotContains('field_ops_routing', $visible);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.routing'))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.routing.store'), [
                        'code' => 'P2601-not-authorized',
                        'mode' => 'MANUAL',
                        'rules' => [[
                            'name' => 'Invalid request',
                            'condition_key' => 'channel',
                            'condition_value' => 'b2b',
                            'action_key' => 'manual_dispatch_required',
                            'action_value' => '1',
                        ]],
                    ])
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.routing.action', [
                        'routingPolicy' => $policy,
                        'action' => 'publish',
                    ]))
                    ->assertForbidden();
                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.routing.action', [
                        'routingPolicy' => $policy,
                        'action' => 'simulate',
                    ]), [
                        'input_keys' => ['channel'],
                        'input_values' => ['b2b'],
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertSame($beforePolicies, DB::table('routing_policies')->count());
        $this->assertSame($beforeTraces, DB::table('routing_decision_traces')->count());
        $this->assertSame('draft', $policy->fresh()->status);
    }

    public function test_authorized_scoped_wholesale_operator_can_create_only_own_store_rules_and_never_retail_rules(): void
    {
        $own = $this->wholesaleStore('P2601-OWN');
        $foreign = $this->wholesaleStore('P2601-FOREIGN');
        $retail = $this->retailStore('P2601-B2C-FOREIGN');
        $actor = $this->actorWithPermissions('P2601_SCOPED', [
            'field_ops.manage', 'reports.view',
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $own->getKey(),
            'role_id' => $actor->roles()->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.routing'))
            ->assertOk()
            ->assertSee($own->code)
            ->assertDontSee($foreign->code)
            ->assertDontSee($retail->code);

        $this->actingAs($actor->fresh())
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'P2601-own-policy',
                'mode' => 'MANUAL',
                'rules' => [[
                    'name' => 'Own B2B store',
                    'condition_key' => 'store_id',
                    'condition_value' => (string) $own->getKey(),
                    'action_key' => 'manual_dispatch_required',
                    'action_value' => '1',
                    'enabled' => '1',
                ]],
            ])
            ->assertSessionHasNoErrors();

        $policy = RoutingPolicy::query()->where('code', 'P2601-own-policy')->with('rules')->firstOrFail();
        $this->assertSame(['store_id' => $own->getKey()], $policy->rules->firstOrFail()->conditions);

        foreach ([$foreign, $retail] as $store) {
            $code = 'P2601-rejected-'.$store->getKey();
            $this->actingAs($actor->fresh())
                ->from(route('admin.field-operations.routing'))
                ->post(route('admin.field-operations.routing.store'), [
                    'code' => $code,
                    'mode' => 'AUTOMATIC',
                    'rules' => [[
                        'name' => 'Foreign store must fail',
                        'condition_key' => 'store_id',
                        'condition_value' => (string) $store->getKey(),
                        'action_key' => 'manual_dispatch_required',
                        'action_value' => '1',
                    ]],
                ])
                ->assertSessionHasErrors('rules');

            $this->assertDatabaseMissing('routing_policies', ['code' => $code]);
        }

        $this->actingAs($actor->fresh())
            ->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), [
                'code' => 'P2601-rejected-channel',
                'mode' => 'MANUAL',
                'rules' => [[
                    'name' => 'Retail channel denied',
                    'condition_key' => 'channel',
                    'condition_value' => 'b2c',
                    'action_key' => 'manual_dispatch_required',
                    'action_value' => '1',
                ]],
            ])
            ->assertSessionHasErrors('rules');
        $this->assertDatabaseMissing('routing_policies', ['code' => 'P2601-rejected-channel']);
    }

    private function actorWithPermissions(string $code, array $permissionCodes): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('code', $permissionCodes)->pluck('id'));
        $actor->roles()->attach($role);

        return $actor;
    }

    private function wholesaleStore(string $code): Store
    {
        $type = StoreType::query()->firstOrCreate(['code' => 'B2B'], ['name' => 'Wholesale']);

        return Store::query()->create([
            'store_type_id' => $type->getKey(),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function retailStore(string $code): Store
    {
        $type = StoreType::query()->firstOrCreate(['code' => 'B2C'], ['name' => 'Retail']);

        return Store::query()->create([
            'store_type_id' => $type->getKey(),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
