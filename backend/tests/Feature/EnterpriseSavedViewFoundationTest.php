<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseSavedView;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseSavedViewService;
use App\Support\EnterpriseGridState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class EnterpriseSavedViewFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_admin_can_save_bounded_module_grid_state_and_reapply_it(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en', 'saved-view-owner@example.test');

        $response = $this->actingAs($admin)->get(route('admin.enterprise-modules.index', [
            'q' => 'sample',
            'state' => 'NOT_INSTALLED',
            'sort' => 'name',
            'direction' => 'desc',
            'columns' => ['state', 'capabilities'],
            'per_page' => 50,
        ]));

        $response->assertOk()
            ->assertSee('data-enterprise-saved-views="enterprise.modules"', false)
            ->assertSee('Saved views')
            ->assertSee('Search modules')
            ->assertSee('Sample Optional');

        $state = EnterpriseGridState::fromRequest(
            Request::create('/admin/administration/modules', 'GET', [
                'q' => 'sample',
                'state' => 'NOT_INSTALLED',
                'sort' => 'name',
                'direction' => 'desc',
                'columns' => ['state', 'capabilities'],
                'per_page' => 50,
            ]),
            ['state'],
            ['name', 'state'],
            ['category', 'state', 'dependencies', 'entitlements', 'capabilities'],
            ['category', 'state', 'dependencies', 'entitlements', 'capabilities'],
        );

        $token = app(EnterpriseSavedViewService::class)->stateToken(
            'enterprise.modules',
            'platform',
            null,
            $state,
        );

        $this->actingAs($admin)->post(route('admin.enterprise-saved-views.store'), [
            'surface_key' => 'enterprise.modules',
            'scope_type' => 'platform',
            'name' => 'Needs review',
            'visibility' => 'private',
            'state_token' => $token,
        ])->assertRedirect();

        $view = EnterpriseSavedView::query()->where('name', 'Needs review')->firstOrFail();
        $this->assertSame('sample', $view->search);
        $this->assertSame(['state' => 'NOT_INSTALLED'], $view->filters);
        $this->assertSame('name', $view->sort_field);
        $this->assertSame('desc', $view->sort_direction);
        $this->assertSame(['state', 'capabilities'], $view->columns);
        $this->assertSame(50, $view->per_page);

        $this->assertSame([
            'state' => 'NOT_INSTALLED',
            'q' => 'sample',
            'sort' => 'name',
            'direction' => 'desc',
            'columns' => ['state', 'capabilities'],
            'per_page' => 50,
        ], app(EnterpriseSavedViewService::class)->queryFor($view));

        $this->actingAs($admin)
            ->get(route('admin.enterprise-modules.index'))
            ->assertOk()
            ->assertSee('Needs review');

        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.saved_view.created']);
    }

    public function test_shared_views_are_scope_visible_but_private_views_stay_owner_only(): void
    {
        $owner = $this->userWithRole('SUPER_ADMIN', 'en', 'saved-view-private@example.test');
        $peer = $this->userWithRole('SUPER_ADMIN', 'en', 'saved-view-peer@example.test');
        $service = app(EnterpriseSavedViewService::class);
        $state = EnterpriseGridState::fromSavedState([
            'filters' => ['state' => 'ENABLED'],
            'columns' => ['state'],
            'per_page' => 25,
        ]);
        $token = $service->stateToken('enterprise.modules', 'platform', null, $state);

        $service->createFromToken($owner, 'enterprise.modules', 'platform', null, 'Private view', 'private', $token);
        $service->createFromToken($owner, 'enterprise.modules', 'platform', null, 'Shared view', 'shared', $token);

        $visible = $service->visible($peer, 'enterprise.modules', 'platform', null)->pluck('name')->all();

        $this->assertSame(['Shared view'], $visible);
    }

    public function test_store_scoped_view_access_cannot_cross_tenants(): void
    {
        [$owner, $storeA] = $this->retailUser('STORE-SAVED-A', 'saved-a@example.test');
        [$other, $storeB] = $this->retailUser('STORE-SAVED-B', 'saved-b@example.test');

        $service = app(EnterpriseSavedViewService::class);
        $state = EnterpriseGridState::fromSavedState([
            'filters' => ['status' => 'open'],
            'columns' => ['status'],
        ]);
        $token = $service->stateToken('enterprise.orders', 'store', $storeA->id, $state);

        $service->createFromToken(
            $owner,
            'enterprise.orders',
            'store',
            $storeA->id,
            'Store A shared',
            'shared',
            $token,
        );

        $this->assertSame(
            ['Store A shared'],
            $service->visible($owner, 'enterprise.orders', 'store', $storeA->id)->pluck('name')->all(),
        );
        $this->assertNotSame($storeA->id, $storeB->id);

        try {
            $service->visible($other, 'enterprise.orders', 'store', $storeA->id);
            $this->fail('Cross-store saved-view access must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_grid_state_rejects_unbounded_query_values_and_keeps_export_handoff_bounded(): void
    {
        $state = EnterpriseGridState::fromRequest(
            Request::create('/grid', 'GET', [
                'q' => str_repeat('x', 200),
                'state' => 'OPEN',
                'client_supplied_scope' => 'everything',
                'sort' => 'not_allowed',
                'direction' => 'sideways',
                'columns' => ['state', 'raw_internal_id', 'state'],
                'per_page' => 10000,
            ]),
            ['state'],
            ['name'],
            ['state', 'category'],
            ['category', 'state'],
        );

        $this->assertSame(120, mb_strlen((string) $state->search));
        $this->assertSame(['state' => 'OPEN'], $state->filters);
        $this->assertNull($state->sortField);
        $this->assertSame('asc', $state->sortDirection);
        $this->assertSame(['state'], $state->columns);
        $this->assertSame(25, $state->perPage);
        $this->assertArrayNotHasKey('per_page', $state->toExportHandoff());
        $this->assertArrayNotHasKey('client_supplied_scope', $state->toExportHandoff()['filters']);
    }

    public function test_tampered_state_token_is_rejected_and_arabic_grid_copy_renders_rtl(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ar', 'saved-view-ar@example.test');

        $this->actingAs($admin)->post(route('admin.enterprise-saved-views.store'), [
            'surface_key' => 'enterprise.modules',
            'scope_type' => 'platform',
            'name' => 'عرض',
            'visibility' => 'private',
            'state_token' => 'tampered-token',
        ])->assertSessionHasErrors('state_token');

        $this->actingAs($admin)
            ->get(route('admin.enterprise-modules.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('العروض المحفوظة')
            ->assertSee('الفلاتر');
    }

    public function test_only_owner_can_delete_a_saved_view(): void
    {
        $owner = $this->userWithRole('SUPER_ADMIN', 'en', 'saved-view-delete@example.test');
        $other = $this->userWithRole('SUPER_ADMIN', 'en', 'saved-view-delete-other@example.test');
        $service = app(EnterpriseSavedViewService::class);
        $state = EnterpriseGridState::fromSavedState(['columns' => ['state']]);
        $view = $service->createFromToken(
            $owner,
            'enterprise.modules',
            'platform',
            null,
            'Owner only',
            'shared',
            $service->stateToken('enterprise.modules', 'platform', null, $state),
        );

        $this->actingAs($other)
            ->delete(route('admin.enterprise-saved-views.destroy', ['savedView' => $view]))
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_saved_views', ['id' => $view->id]);

        $this->actingAs($owner)
            ->delete(route('admin.enterprise-saved-views.destroy', ['savedView' => $view]))
            ->assertRedirect();

        $this->assertDatabaseMissing('enterprise_saved_views', ['id' => $view->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.saved_view.deleted']);
    }

    private function userWithRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    /** @return array{User, Store} */
    private function retailUser(string $storeCode, string $email): array
    {
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'orders.view'],
            ['name' => 'Orders view'],
        );
        $role = Role::query()->create([
            'code' => 'SAVED_VIEW_'.str_replace('-', '_', $storeCode),
            'name' => 'Saved view '.$storeCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $user = User::query()->create([
            'name' => $storeCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }
}
