<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P292: plural Cashboxes is the authorized, searchable Treasury collection.
 *
 * P293 already owns per-record create/update authority. These assertions cover
 * collection filters, pagination and multi-store lookup isolation instead.
 */
final class V2CashboxesCollectionAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_cashboxes_search_and_status_filters_cannot_leak_another_store_in_ar_or_en(): void
    {
        $own = $this->retailStore('V2-P292-FILTER-OWN');
        $foreign = $this->retailStore('V2-P292-FILTER-FOREIGN');
        $active = $this->cashbox($own, 'V2-P292-NEEDLE-A');
        $inactive = $this->cashbox($own, 'V2-P292-NEEDLE-I', false);
        $foreignCashbox = $this->cashbox($foreign, 'V2-P292-NEEDLE-F');
        $actor = $this->retailActor([$own]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', [
                    'tab' => 'cashboxes',
                    'store_id' => $own->id,
                    'q' => 'V2-P292-NEEDLE',
                    'status' => 'active',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('treasury.cashboxes'))
                ->assertSee($active->code)
                ->assertDontSee($inactive->code)
                ->assertDontSee($foreignCashbox->code);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', [
                    'tab' => 'cashboxes',
                    'store_id' => $own->id,
                    'q' => 'V2-P292-NEEDLE',
                    'status' => 'inactive',
                ]))
                ->assertOk()
                ->assertSee($inactive->code)
                ->assertDontSee($active->code)
                ->assertDontSee($foreignCashbox->code);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', [
                    'tab' => 'cashboxes',
                    'store_id' => $foreign->id,
                    'q' => $foreignCashbox->code,
                ]))
                ->assertNotFound();
        }
    }

    public function test_cashboxes_pagination_is_bounded_and_preserves_search_and_store_scope(): void
    {
        $own = $this->retailStore('V2-P292-PAGING-OWN');
        $foreign = $this->retailStore('V2-P292-PAGING-FOREIGN');
        $actor = $this->retailActor([$own]);

        foreach (range(1, 26) as $number) {
            $this->cashbox($own, sprintf('V2-P292-LIST-%02d', $number));
        }
        $foreignCashbox = $this->cashbox($foreign, 'V2-P292-LIST-FOREIGN');

        $query = [
            'tab' => 'cashboxes',
            'store_id' => $own->id,
            'q' => 'V2-P292-LIST',
            'status' => 'active',
            'per_page' => 25,
        ];

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', $query))
            ->assertOk()
            ->assertSee('V2-P292-LIST-01')
            ->assertDontSee('V2-P292-LIST-26')
            ->assertDontSee($foreignCashbox->code)
            ->assertSee('cashboxes_page=2');

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', [...$query, 'cashboxes_page' => 2]))
            ->assertOk()
            ->assertSee('V2-P292-LIST-26')
            ->assertDontSee('V2-P292-LIST-01')
            ->assertDontSee($foreignCashbox->code);
    }

    public function test_multi_store_treasury_selector_only_exposes_authorized_locations(): void
    {
        $first = $this->retailStore('V2-P292-SELECT-A');
        $second = $this->retailStore('V2-P292-SELECT-B');
        $foreign = $this->retailStore('V2-P292-SELECT-FOREIGN');
        $firstCashbox = $this->cashbox($first, 'V2-P292-SELECT-CASH-A');
        $secondCashbox = $this->cashbox($second, 'V2-P292-SELECT-CASH-B');
        $foreignCashbox = $this->cashbox($foreign, 'V2-P292-SELECT-CASH-FOREIGN');
        $actor = $this->retailActor([$first, $second]);

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', ['tab' => 'cashboxes']))
            ->assertOk()
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertDontSee($foreign->name)
            ->assertSee($firstCashbox->code)
            ->assertSee($secondCashbox->code)
            ->assertDontSee($foreignCashbox->code);

        $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', [
                'tab' => 'cashboxes',
                'store_id' => $second->id,
            ]))
            ->assertOk()
            ->assertSee($secondCashbox->code)
            ->assertDontSee($firstCashbox->code)
            ->assertDontSee($foreignCashbox->code);
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

    private function cashbox(Store $store, string $code, bool $active = true): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $code,
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => $active,
        ]);
    }

    /** @param list<Store> $stores */
    private function retailActor(array $stores): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id;

        foreach ($stores as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }
}
