<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoutingPolicy;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\RoutingPolicyService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RoutingPoliciesStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_store_limited_routing_list_does_not_disclose_foreign_policy_versions_but_global_owner_can_see_them(): void
    {
        $storeA = $this->wholesaleStore('P270-STORE-A');
        $storeB = $this->wholesaleStore('P270-STORE-B');
        $a = $this->operator('P270-A', $storeA->id);
        $b = $this->operator('P270-B', $storeB->id);
        $own = $this->draft($a, 'P270-OWN', $storeA->id);
        $foreign = $this->draft($b, 'P270-FOREIGN', $storeB->id);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            $a->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($a->fresh())
                ->get(route('admin.field-operations.routing'))
                ->assertOk()
                ->assertSee('dir="'.$dir.'"', false)
                ->assertSee('data-nav-item="field_ops_routing"', false)
                ->assertSee($own->code)
                ->assertDontSee($foreign->code);
        }

        $owner = $this->superAdmin();
        $this->actingAs($owner)->get(route('admin.field-operations.routing'))
            ->assertOk()
            ->assertSee($own->code)
            ->assertSee($foreign->code);
    }

    public function test_scoped_actor_cannot_create_unscoped_rules_or_hijack_an_existing_global_policy_code(): void
    {
        $store = $this->wholesaleStore('P270-SCOPED');
        $actor = $this->operator('P270-CREATOR', $store->id);
        $owner = $this->superAdmin();
        $global = app(RoutingPolicyService::class)->createDraft($owner, 'P270-GLOBAL', 'MANUAL', [[
            'name' => 'Global B2B', 'conditions' => ['channel' => 'b2b'],
            'actions' => ['manual_dispatch_required' => true], 'enabled' => true,
        ]]);

        $this->actingAs($actor)->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), $this->requestPayload('P270-UNSCOPED', 'channel', 'b2b'))
            ->assertSessionHasErrors('rules');

        $this->actingAs($actor)->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), $this->requestPayload($global->code, 'store_id', (string) $store->id))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('routing_policies', ['code' => 'P270-UNSCOPED']);
        $this->assertSame(1, RoutingPolicy::query()->where('code', $global->code)->count());
        $this->assertSame('draft', $global->fresh()->status);
    }

    public function test_scoped_operator_cannot_publish_rollback_or_simulate_foreign_policy_by_guessing_its_id(): void
    {
        $storeA = $this->wholesaleStore('P270-ACTOR-STORE');
        $storeB = $this->wholesaleStore('P270-OTHER-STORE');
        $actor = $this->operator('P270-ACTOR', $storeA->id);
        $other = $this->operator('P270-OTHER', $storeB->id);
        $foreignDraft = $this->draft($other, 'P270-FOREIGN-DRAFT', $storeB->id);
        $foreignPublished = $this->draft($other, 'P270-FOREIGN-PUBLISHED', $storeB->id);
        app(RoutingPolicyService::class)->publish($other, $foreignPublished);

        $before = DB::table('routing_policies')->count();
        foreach ([
            [$foreignDraft, 'publish'],
            [$foreignPublished, 'rollback'],
            [$foreignPublished, 'simulate'],
        ] as [$policy, $action]) {
            $this->actingAs($actor)
                ->post(route('admin.field-operations.routing.action', [
                    'routingPolicy' => $policy,
                    'action' => $action,
                ]), $action === 'simulate' ? [
                    'input_keys' => ['store_id'],
                    'input_values' => [(string) $storeB->id],
                ] : [])
                ->assertNotFound();
        }

        $this->assertSame($before, DB::table('routing_policies')->count());
        $this->assertSame('draft', $foreignDraft->fresh()->status);
        $this->assertSame('published', $foreignPublished->fresh()->status);
        $this->assertDatabaseCount('routing_decision_traces', 0);
    }

    public function test_legacy_coordinate_only_rule_remains_a_draft_but_cannot_be_published_globally_by_scoped_actor(): void
    {
        $store = $this->wholesaleStore('P270-COORD-STORE');
        $actor = $this->operator('P270-COORD-ACTOR', $store->id);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.store'), $this->requestPayload('P270-COORD-DRAFT', 'latitude', '29.375900'))
            ->assertSessionHasNoErrors();

        $policy = RoutingPolicy::query()->where('code', 'P270-COORD-DRAFT')->firstOrFail();
        $this->assertSame('draft', $policy->status);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.action', [
                'routingPolicy' => $policy,
                'action' => 'publish',
            ]))
            ->assertNotFound();

        $this->assertSame('draft', $policy->fresh()->status);
    }

    public function test_scoped_operator_can_publish_rollback_and_version_an_exact_owned_wholesale_policy(): void
    {
        $store = $this->wholesaleStore('P270-VALID-WHOLESALE');
        $actor = $this->operator('P270-VALID-OPERATOR', $store->id);

        $this->actingAs($actor)->from(route('admin.field-operations.routing'))
            ->post(route('admin.field-operations.routing.store'), $this->requestPayload('P270-VALID', 'store_id', (string) $store->id))
            ->assertSessionHasNoErrors();

        $policy = RoutingPolicy::query()->where('code', 'P270-VALID')->firstOrFail();

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.action', [
                'routingPolicy' => $policy,
                'action' => 'publish',
            ]))
            ->assertRedirect();

        $this->assertSame('published', $policy->fresh()->status);

        $this->actingAs($actor)
            ->post(route('admin.field-operations.routing.action', [
                'routingPolicy' => $policy,
                'action' => 'rollback',
            ]), ['reason' => 'Return to prior version'])
            ->assertRedirect();

        $this->assertSame(2, RoutingPolicy::query()->where('code', 'P270-VALID')->count());
        $this->assertSame(1, RoutingPolicy::query()->where('code', 'P270-VALID')->where('status', 'published')->count());
    }

    private function operator(string $code, int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => $code, 'name' => $code, 'scope' => 'global',
            'is_system' => false, 'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('code', ['field_ops.manage', 'reports.view'])->pluck('id'),
        );
        $actor->roles()->attach($role);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $storeId, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $actor;
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function wholesaleStore(string $code): Store
    {
        $type = StoreType::query()->firstOrCreate(['code' => 'B2B'], ['name' => 'Wholesale']);

        return Store::query()->create([
            'store_type_id' => $type->id, 'code' => $code,
            'name' => $code, 'is_active' => true,
        ]);
    }

    private function draft(User $actor, string $code, int $storeId): RoutingPolicy
    {
        return app(RoutingPolicyService::class)->createDraft($actor, $code, 'MANUAL', [[
            'name' => 'Own B2B store', 'conditions' => ['store_id' => $storeId],
            'actions' => ['manual_dispatch_required' => true], 'enabled' => true,
        ]]);
    }

    /** @return array<string, mixed> */
    private function requestPayload(string $code, string $conditionKey, string $conditionValue): array
    {
        return [
            'code' => $code, 'mode' => 'MANUAL',
            'rules' => [[
                'name' => 'Store policy',
                'condition_key' => $conditionKey,
                'condition_value' => $conditionValue,
                'action_key' => 'manual_dispatch_required',
                'action_value' => '1',
                'enabled' => '1',
            ]],
        ];
    }
}
