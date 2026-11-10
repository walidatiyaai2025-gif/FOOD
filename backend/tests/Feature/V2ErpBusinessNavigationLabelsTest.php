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

final class V2ErpBusinessNavigationLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_erp_sidebar_and_workspace_names_are_business_facing_in_both_locales(): void
    {
        $wholesale = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        foreach ([
            'ar' => [
                'direction' => 'rtl',
                'wms' => 'إدارة المخازن والمخزون',
                'customer_service' => 'خدمة العملاء والحسابات',
                'bi' => 'التقارير وتحليلات الأعمال',
                'my_work' => 'مهامي والاعتمادات',
            ],
            'en' => [
                'direction' => 'ltr',
                'wms' => 'Warehouses and Inventory',
                'customer_service' => 'Customer Service and Accounts',
                'bi' => 'Reports and Business Analytics',
                'my_work' => 'My Tasks and Approvals',
            ],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();

            foreach (['wms', 'customer_service', 'bi', 'my_work'] as $module) {
                $key = 'admin.erp_center.modules.'.$module.'.title';
                $this->assertSame($expected[$module], __($key));
                $this->assertNotSame($key, __($key));
            }

            $groups = collect(app(AdminNavigation::class)->groupsFor($wholesale->fresh()));
            $erp = $groups->firstWhere('key', 'erp');
            $this->assertIsArray($erp);
            $items = collect($erp['children'])->keyBy('key');

            $this->assertTrue($items->has('erp_center'));
            $this->assertSame('admin.erp.index', $items->get('erp_center')['route']);
            $this->assertTrue($items->has('erp_my_work'));
            $this->assertSame('admin.erp_center.modules.my_work.title', $items->get('erp_my_work')['label']);
            $this->assertSame('admin.action-center.index', $items->get('erp_my_work')['route']);

            $this->actingAs($wholesale->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['my_work']);
        }
    }

    public function test_business_facing_labels_do_not_grant_platform_erp_to_retail_store_only_user(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-2877-RETAIL',
            'name' => 'V2 Business Labels Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $this->assertFalse(
                collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
                    ->contains('key', 'erp'),
                'Retail store role must not acquire platform ERP access through a translated label.',
            );
            $this->actingAs($retail->fresh())
                ->get(route('admin.erp.index'))
                ->assertForbidden();
            $this->actingAs($retail->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeId]))
                ->assertOk();
        }
    }
}
