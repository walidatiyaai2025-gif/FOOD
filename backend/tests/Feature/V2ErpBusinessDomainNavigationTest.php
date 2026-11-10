<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P358: authorized ERP module destinations move by domain, never by privilege. */
final class V2ErpBusinessDomainNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_GROUPS = [
        'erp_procurement' => 'catalog',
        'erp_wms' => 'catalog',
        'erp_finance' => 'finance',
        'erp_treasury' => 'finance',
        'erp_bi' => 'analytics',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        foreach (['procurement', 'inventory_wms', 'finance', 'treasury', 'enterprise_bi'] as $code) {
            config()->set('enterprise_module_readiness.certified_versions.'.$code, app(ModuleRegistry::class)->manifest($code)->version);
        }
    }

    public function test_approved_erp_modules_render_once_in_business_groups_for_both_locales(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            // Existing ERP Center test bootstrap creates disposable enabled
            // module/entitlement state; production remains gated by readiness.
            $this->actingAs($actor->fresh())->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            foreach (self::EXPECTED_GROUPS as $key => $expectedGroup) {
                $found = [];
                foreach ($groups as $group) {
                    foreach ($group['children'] as $item) {
                        if ($item['key'] === $key) {
                            $found[] = $group['key'];
                        }
                    }
                }
                $this->assertSame([$expectedGroup], $found, $locale.' '.$key);
            }
        }
    }

    public function test_retail_role_cannot_gain_erp_access_from_a_reclassified_sidebar_group(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-P358-RETAIL',
            'name' => 'V2 P358 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $groups = app(AdminNavigation::class)->groupsFor($retail->fresh());
            $keys = collect($groups)->flatMap(
                static fn (array $group): array => array_column($group['children'], 'key'),
            )->all();
            foreach (array_keys(self::EXPECTED_GROUPS) as $key) {
                $this->assertNotContains($key, $keys);
            }
            $this->actingAs($retail->fresh())->get(route('admin.erp.procurement.index'))->assertForbidden();
            $this->actingAs($retail->fresh())->get(route('admin.b2c.module', [
                'module' => 'dashboard',
                'store_id' => $storeId,
            ]))->assertOk();
        }
    }
}
