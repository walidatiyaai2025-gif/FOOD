<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2OverviewActionCenterAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_action_center_is_a_real_authorized_overview_destination_in_arabic_and_english(): void
    {
        $global = $this->globalActor('B2B_ADMIN');

        foreach ([
            'ar' => ['label' => 'مركز الإجراءات', 'direction' => 'rtl'],
            'en' => ['label' => 'Action Center', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $global->forceFill(['locale' => $locale])->save();

            $overview = collect(app(AdminNavigation::class)->groupsFor($global->fresh()))
                ->firstWhere('key', 'overview');
            $this->assertIsArray($overview);
            $link = collect($overview['children'])->firstWhere('key', 'enterprise_action_center');
            $this->assertIsArray($link);
            $this->assertSame('admin.action-center.index', $link['route']);
            $this->assertSame('enterprise_action_center.nav_title', $link['label']);
            $this->assertSame($expected['label'], __($link['label']));

            $this->actingAs($global->fresh())
                ->get(route('admin.action-center.index'))
                ->assertOk()
                ->assertViewHas('navContext', 'enterprise_action_center')
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['label']);
        }
    }

    public function test_assigned_retail_store_admin_can_open_my_work_but_foreign_store_is_not_its_scope(): void
    {
        $ownedStore = $this->retailStore('V2-P177-OWNED');
        $foreignStore = $this->retailStore('V2-P177-FOREIGN');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $ownedStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(app(AdminNavigation::class)->canAccessMyWork($retail->fresh()));
        $this->assertTrue(
            collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->contains('key', 'enterprise_action_center'),
        );

        $this->actingAs($retail->fresh())
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertViewHas('navContext', 'enterprise_action_center');

        $this->actingAs($retail->fresh())
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $ownedStore]))
            ->assertOk();
        $this->actingAs($retail->fresh())
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $foreignStore]))
            ->assertNotFound();
    }

    public function test_sidebar_visibility_does_not_grant_action_center_http_permissions(): void
    {
        $unassignedRetail = User::factory()->create(['is_active' => true]);
        $inactiveGlobal = $this->globalActor('B2B_ADMIN');
        $inactiveGlobal->forceFill(['is_active' => false])->save();

        foreach ([
            $this->globalActor('CUSTOMER_SERVICE_AGENT'),
            $unassignedRetail,
            $inactiveGlobal,
        ] as $denied) {
            $this->assertFalse(app(AdminNavigation::class)->canAccessMyWork($denied->fresh()));
            $this->assertFalse(
                collect(app(AdminNavigation::class)->groupsFor($denied->fresh()))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->contains('key', 'enterprise_action_center'),
            );

            $this->actingAs($denied->fresh())
                ->get(route('admin.action-center.index'))
                ->assertForbidden();
            $this->actingAs($denied->fresh())
                ->get(route('admin.action-center.export'))
                ->assertForbidden();
            $this->actingAs($denied->fresh())
                ->post(route('admin.action-center.decide', [
                    'workflowRequest' => 999999,
                    'stepCode' => 'approval',
                ]), ['decision' => 'APPROVED'])
                ->assertForbidden();
        }
    }

    private function globalActor(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
