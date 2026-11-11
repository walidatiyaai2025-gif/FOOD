<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T356R8 / #2725: existing Routing Policies navigation moves under the
 * canonical Fleet & Field Operations business group without new privileges.
 */
final class V2FieldOpsRoutingSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_routing_keeps_one_registered_field_operations_owner_and_actual_permission_contract(): void
    {
        $manager = app(AdminSidebarManager::class);
        $registry = $manager->registry();
        $layout = $manager->layoutData();
        $item = $registry['items']['field_ops_routing'];

        $this->assertSame('field_operations', $item['group']);
        $this->assertSame('field_operations', $layout['items']['field_ops_routing']['group_key']);
        $this->assertSame('admin.field_operations.routing', $item['label']);
        $this->assertSame('global', $item['scope']);
        $this->assertSame(['territories.manage', 'field_ops.manage'], $item['permissions']);
        $this->assertSame('any', $item['mode']);
        $this->assertTrue(Route::has('admin.field-operations.routing'));
        $this->assertTrue(Route::has('admin.field-operations.routing.store'));

        $this->assertSame('الأسطول والعمليات الميدانية', __('admin.nav_groups.field_operations', [], 'ar'));
        $this->assertSame('Fleet & Field Operations', __('admin.nav_groups.field_operations', [], 'en'));
        $this->assertSame('التوجيه والمسارات', __('admin.field_operations.routing', [], 'ar'));
        $this->assertSame('Routing & Routes', __('admin.field_operations.routing', [], 'en'));
    }

    public function test_authorized_global_operator_sees_one_real_routing_route_and_localized_dashboard(): void
    {
        $actor = $this->globalActor('SUPER_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $matching = [];
            foreach (app(AdminNavigation::class)->groupsFor($actor->fresh()) as $group) {
                foreach ($group['children'] as $item) {
                    if ($item['key'] === 'field_ops_routing') {
                        $matching[] = ['group' => $group['key'], 'item' => $item];
                    }
                }
            }

            $this->assertCount(1, $matching, $locale.' routing key must be unique');
            $this->assertSame('field_operations', $matching[0]['group']);
            $this->assertSame('admin.field-operations.routing', $matching[0]['item']['route']);
            $this->assertSame('admin.field_operations.routing', $matching[0]['item']['label']);
            $this->assertSame([], $matching[0]['item']['params']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.routing'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="field_operations"', false)
                ->assertSee('data-nav-item="field_ops_routing"', false)
                ->assertSee(__('admin.field_operations.routing', [], $locale));
        }
    }

    public function test_retail_roleless_and_disabled_users_cannot_read_or_mutate_b2b_routing(): void
    {
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-2725-RETAIL',
            'name' => 'V2 Routing Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $inactive = $this->globalActor('SUPER_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        $before = DB::table('routing_policies')->count();

        foreach ([$retail, $roleless, $inactive] as $actor) {
            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);

                $visibleKeys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key')
                    ->all();
                $this->assertNotContains('field_ops_routing', $visibleKeys);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.field-operations.routing'))
                    ->assertForbidden();

                $this->actingAs($actor->fresh())
                    ->post(route('admin.field-operations.routing.store'), [
                        'code' => 'V2-2725-UNAUTHORIZED',
                        'mode' => 'MANUAL',
                    ])->assertForbidden();
            }
        }

        $this->assertSame($before, DB::table('routing_policies')->count());
    }

    private function globalActor(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
