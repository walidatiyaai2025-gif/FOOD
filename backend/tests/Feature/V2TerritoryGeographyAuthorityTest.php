<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\Role;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TerritoryGeographyAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_territory_screen_uses_existing_field_operations_route_map_and_bilingual_navigation(): void
    {
        $actor = $this->user('SUPER_ADMIN', 'p269-owner@example.test');
        $country = $this->geo('country', 'KW', 'KW');
        ServiceTerritory::query()->create([
            'code' => 'P269-TERRITORY',
            'name_ar' => 'منطقة الاختبار',
            'name_en' => 'Territory Test',
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 10,
        ]);

        $items = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(fn (array $group) => $group['children'])
            ->keyBy('key');

        $this->assertTrue($items->has('field_ops_territories'));
        $this->assertSame('admin.field-operations.territories', $items['field_ops_territories']['route']);

        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor)->get(route('admin.field-operations.territories'))
                ->assertOk()
                ->assertViewHas('navContext', 'field_ops_territories')
                ->assertSee('data-field-operations-page="territories"', false)
                ->assertSee('id="fieldops-coverage-map"', false)
                ->assertSee('data-pagination-required', false)
                ->assertSee('P269-TERRITORY')
                ->assertSee(__('field_operations.pages.territories.title'))
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
        }
    }

    public function test_retail_store_actor_cannot_open_or_forge_global_geography_and_territory_writes(): void
    {
        $actor = User::query()->create([
            'name' => 'Retail Area Actor',
            'email' => 'p269-retail@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $storeId = $this->store('B2C', 'P269-RETAIL');
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $country = $this->geo('country', 'KW', 'KW');
        $territory = ServiceTerritory::query()->create([
            'code' => 'P269-RETAIL-DENIED',
            'name_ar' => 'منطقة محمية',
            'name_en' => 'Protected Territory',
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 1,
        ]);

        $this->actingAs($actor)->get(route('admin.field-operations.territories'))
            ->assertForbidden();
        $this->actingAs($actor)->post(route('admin.field-operations.geography.store'), [
            'type' => 'region', 'code' => 'FORGED', 'country_code' => 'KW',
            'name_ar' => 'مزيف', 'name_en' => 'Forged', 'parent_id' => $country->id,
        ])->assertForbidden();
        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), $this->territoryPayload($country->id))
            ->assertForbidden();

        $this->actingAs($actor)->post(route('admin.field-operations.territories.geometry.store', $territory), [
            'geojson' => json_encode([
                'type' => 'Polygon',
                'coordinates' => [[[47.0, 29.0], [48.0, 29.0], [48.0, 30.0], [47.0, 29.0]]],
            ], JSON_THROW_ON_ERROR),
        ])->assertForbidden();

        $this->assertDatabaseMissing('service_territories', ['code' => 'P269-REGION']);
        $this->assertDatabaseMissing('geography_nodes', ['code' => 'FORGED']);
        $this->assertDatabaseCount('territory_geometries', 0);
    }

    public function test_territory_creation_rejects_incorrect_country_type_and_foreign_geography_lineage(): void
    {
        $actor = $this->user('SUPER_ADMIN', 'p269-country@example.test');
        $kuwait = $this->geo('country', 'KW', 'KW');
        $egypt = $this->geo('country', 'EG', 'EG');
        $egyptGovernorate = $this->geo('governorate', 'GOV-EG', 'EG', $egypt->id);

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), $this->territoryPayload($egyptGovernorate->id))
            ->assertSessionHasErrors('country_node_id');

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), [
            ...$this->territoryPayload($kuwait->id),
            'governorate_node_id' => $egyptGovernorate->id,
        ])->assertSessionHasErrors('governorate_node_id');

        $this->assertDatabaseCount('service_territories', 0);
    }

    public function test_territory_creation_checks_each_selected_ancestor_and_preserves_valid_country_tree(): void
    {
        $actor = $this->user('SUPER_ADMIN', 'p269-tree@example.test');
        $country = $this->geo('country', 'KW', 'KW');
        $north = $this->geo('governorate', 'NORTH', 'KW', $country->id);
        $south = $this->geo('governorate', 'SOUTH', 'KW', $country->id);
        $city = $this->geo('city', 'SOUTH-CITY', 'KW', $south->id);

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), [
            ...$this->territoryPayload($country->id),
            'governorate_node_id' => $north->id,
            'city_node_id' => $city->id,
        ])->assertSessionHasErrors('city_node_id');

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), [
            ...$this->territoryPayload($country->id),
            'governorate_node_id' => $south->id,
            'city_node_id' => $city->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('service_territories', [
            'code' => 'P269-REGION',
            'country_node_id' => $country->id,
            'governorate_node_id' => $south->id,
            'city_node_id' => $city->id,
        ]);
    }

    public function test_new_geography_cannot_be_attached_to_a_foreign_country_parent(): void
    {
        $actor = $this->user('SUPER_ADMIN', 'p269-parent@example.test');
        $foreign = $this->geo('country', 'EG', 'EG');

        $payload = [
            'type' => 'governorate', 'code' => 'P269-GOV',
            'name_ar' => 'محافظة', 'name_en' => 'Governorate',
            'country_code' => 'KW', 'parent_id' => $foreign->id,
        ];

        $this->actingAs($actor)->post(route('admin.field-operations.geography.store'), $payload)
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('geography_nodes', ['code' => 'P269-GOV']);
    }

    public function test_default_warehouse_must_belong_to_an_active_authorized_wholesale_store(): void
    {
        $actor = $this->user('SUPER_ADMIN', 'p269-warehouse@example.test');
        $country = $this->geo('country', 'KW', 'KW');
        $retail = $this->store('B2C', 'P269-RETAIL-WH');
        $wholesale = $this->store('B2B', 'P269-WHOLESALE-WH');

        $foreignWarehouse = DB::table('warehouses')->insertGetId([
            'store_id' => $retail, 'code' => 'P269-B2C-WH',
            'name' => 'Retail Warehouse', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizedWarehouse = DB::table('warehouses')->insertGetId([
            'store_id' => $wholesale, 'code' => 'P269-B2B-WH',
            'name' => 'Wholesale Warehouse', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), [
            ...$this->territoryPayload($country->id),
            'default_warehouse_id' => $foreignWarehouse,
        ])->assertSessionHasErrors('default_warehouse_id');

        $this->actingAs($actor)->post(route('admin.field-operations.territories.store'), [
            ...$this->territoryPayload($country->id),
            'default_warehouse_id' => $authorizedWarehouse,
        ])->assertRedirect();

        $this->assertDatabaseHas('service_territories', [
            'code' => 'P269-REGION',
            'default_warehouse_id' => $authorizedWarehouse,
        ]);
    }

    private function user(string $roleCode, string $email): User
    {
        $actor = User::query()->create([
            'name' => 'P269 Actor', 'email' => $email,
            'password' => 'password', 'locale' => 'en', 'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function geo(string $type, string $code, string $countryCode, ?int $parentId = null): GeographyNode
    {
        return GeographyNode::query()->create([
            'type' => $type, 'code' => $code,
            'name_ar' => 'اسم '.$code,
            'name_en' => $code,
            'country_code' => $countryCode,
            'parent_id' => $parentId,
            'is_active' => true,
        ]);
    }

    private function territoryPayload(int $countryId): array
    {
        return [
            'code' => 'P269-REGION',
            'name_ar' => 'منطقة',
            'name_en' => 'Territory',
            'country_node_id' => $countryId,
            'status' => 'active',
            'priority' => 1,
        ];
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code, 'name' => $code, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
