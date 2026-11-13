<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P293: the existing Treasury Cashbox tab is a real authorized business
 * destination, not a second, visibility-only finance permission system.
 */
final class V2CashboxSidebarAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_finance_sidebar_opens_real_cashbox_tab_in_both_locales(): void
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $cashbox = $this->cashbox($store, 'V2-P293-WHOLESALE');
        $actor = $this->globalActor('B2B_ADMIN');

        foreach ([
            'ar' => ['dir' => 'rtl', 'tab' => 'الخزن', 'label' => 'الخزينة'],
            'en' => ['dir' => 'ltr', 'tab' => 'Cashboxes', 'label' => 'Treasury'],
        ] as $locale => $expected) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $treasury = collect($groups)->firstWhere('key', 'treasury');
            $this->assertNotNull($treasury);
            $items = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'treasury_master')
                ->values();
            $this->assertCount(1, $items);
            $this->assertContains('treasury_master', array_column($treasury['children'], 'key'));
            $this->assertSame('admin.treasury.master.index', $items->first()['route']);
            $this->assertSame($expected['label'], __($items->first()['label']));

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes']))
                ->assertOk()
                ->assertSee('dir="'.$expected['dir'].'"', false)
                ->assertSee($expected['tab'])
                ->assertSee($cashbox->code)
                ->assertSee('data-foodex-mobile-web-page="treasury-master"', false);
        }
    }

    public function test_retail_cashboxes_are_store_isolated_with_authorized_create_and_update(): void
    {
        $own = $this->retailStore('V2-P293-OWN');
        $foreign = $this->retailStore('V2-P293-FOREIGN');
        $ownCashbox = $this->cashbox($own, 'V2-P293-OWN-CASH');
        $foreignCashbox = $this->cashbox($foreign, 'V2-P293-FOREIGN-CASH');
        $actor = $this->retailActor($own, 'RETAIL_FINANCE');

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'store_id' => $own->id]))
                ->assertOk()
                ->assertSee($ownCashbox->code)
                ->assertDontSee($foreignCashbox->code);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'store_id' => $foreign->id]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'edit_cashbox' => $foreignCashbox->id]))
                ->assertNotFound();

            $forgedCode = 'V2-P293-FORGED-'.strtoupper($locale);
            $this->actingAs($actor->fresh())
                ->post(route('admin.treasury.cashboxes.store'), $this->cashboxPayload($foreign, $forgedCode))
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->patch(route('admin.treasury.cashboxes.update', ['cashbox' => $foreignCashbox->id]), $this->cashboxPayload($foreign, $foreignCashbox->code))
                ->assertNotFound();

            $this->assertDatabaseMissing('enterprise_cashboxes', ['code' => $forgedCode]);
        }

        $this->actingAs($actor->fresh())
            ->post(route('admin.treasury.cashboxes.store'), $this->cashboxPayload($own, 'v2-p293-created'))
            ->assertRedirect(route('admin.treasury.master.index', ['tab' => 'cashboxes']));
        $created = EnterpriseCashbox::query()->where('code', 'V2-P293-CREATED')->firstOrFail();
        $this->assertSame($own->id, (int) $created->store_id);

        $payload = $this->cashboxPayload($own, 'V2-P293-CREATED');
        $payload['name'] = 'Reviewed cashbox';
        $this->actingAs($actor->fresh())
            ->patch(route('admin.treasury.cashboxes.update', ['cashbox' => $created->id]), $payload)
            ->assertRedirect(route('admin.treasury.master.index', ['tab' => 'cashboxes']));

        $this->assertSame('Reviewed cashbox', $created->fresh()->name);
        $this->assertSame('Test cashbox', $foreignCashbox->fresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $created->id,
            'event' => 'enterprise.treasury.cashbox.updated',
        ]);
    }

    public function test_view_only_role_can_read_but_cannot_create_or_update_cashboxes(): void
    {
        $store = $this->retailStore('V2-P293-READ');
        $cashbox = $this->cashbox($store, 'V2-P293-READ-CASH');
        $role = Role::query()->create([
            'code' => 'V2_P293_READ_ONLY',
            'name' => 'Cashbox reader',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'finance.view')->firstOrFail()->id);
        $actor = $this->retailActor($store, $role->code);

        $this->assertTrue($actor->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->hasPermission('finance.manage', $store->id));
        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'store_id' => $store->id]))
            ->assertOk()
            ->assertSee($cashbox->code)
            ->assertDontSee(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'create' => 'cashbox']));

        $this->actingAs($actor)
            ->post(route('admin.treasury.cashboxes.store'), $this->cashboxPayload($store, 'V2-P293-DENIED'))
            ->assertNotFound();
        $this->actingAs($actor)
            ->patch(route('admin.treasury.cashboxes.update', ['cashbox' => $cashbox->id]), $this->cashboxPayload($store, $cashbox->code))
            ->assertNotFound();

        $this->assertDatabaseMissing('enterprise_cashboxes', ['code' => 'V2-P293-DENIED']);
        $this->assertSame('Test cashbox', $cashbox->fresh()->name);
    }

    public function test_explicit_deny_has_precedence_per_store_without_leaking_another_scope(): void
    {
        $first = $this->retailStore('V2-P293-DENY-A');
        $second = $this->retailStore('V2-P293-DENY-B');
        $secondCashbox = $this->cashbox($second, 'V2-P293-DENY-B-CASH');
        $actor = $this->retailActor($first, 'RETAIL_FINANCE');
        $this->attachRetailRole($actor, $second, 'RETAIL_FINANCE');

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$first->id,
            'store_id' => $first->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $first->id));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $second->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'store_id' => $first->id]))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes', 'store_id' => $second->id]))
            ->assertOk()
            ->assertSee($secondCashbox->code);

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes']))
            ->assertForbidden();
    }

    private function retailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function cashbox(Store $store, string $code): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Test cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function retailActor(Store $store, string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->attachRetailRole($actor, $store, $roleCode);

        return $actor;
    }

    private function attachRetailRole(User $actor, Store $store, string $roleCode): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    /** @return array<string, int|string> */
    private function cashboxPayload(Store $store, string $code): array
    {
        return [
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Test cashbox',
            'operator_visibility' => 'store',
            'approval_visibility' => 'store',
            'is_active' => 1,
        ];
    }
}
