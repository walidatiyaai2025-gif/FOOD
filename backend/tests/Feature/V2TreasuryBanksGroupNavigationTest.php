<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TreasuryBanksGroupNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_treasury_and_banks_reuses_authorized_children_without_moving_finance_or_granting_access(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('admin.nav_groups.treasury', $registry['groups']['treasury']['label']);
        $this->assertSame('treasury', $registry['items']['treasury_master']['group']);
        $this->assertSame('treasury', $registry['items']['erp_treasury']['group']);
        $this->assertSame('finance', $registry['items']['erp_finance']['group']);

        $actor = $this->globalUser('B2B_ADMIN');
        $groups = collect(app(AdminNavigation::class)->groupsFor($actor))->keyBy('key');
        $this->assertTrue($groups->has('treasury'));
        $treasury = collect($groups->get('treasury')['children'])->keyBy('key');

        // Treasury master is a real already-authorized page, not a decorative link.
        $this->assertTrue($treasury->has('treasury_master'));
        $this->assertSame('admin.treasury.master.index', $treasury->get('treasury_master')['route']);
        $this->actingAs($actor)->get(route('admin.treasury.master.index'))->assertOk();

        if (! app(ModuleRegistry::class)->isEnabled('treasury')) {
            $this->assertFalse($treasury->has('erp_treasury'),
                'Disabled/unready ERP Treasury must not be exposed by the new group.');
        }
        $finance = collect($groups->get('finance', ['children' => []])['children'])->pluck('key');
        $this->assertFalse($finance->contains('treasury_master'));
        $this->assertFalse($finance->contains('erp_treasury'));
        $this->assertFalse($treasury->contains('erp_finance'));
    }

    public function test_retail_without_platform_treasury_access_sees_no_treasury_group_or_route(): void
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P291-RETAIL',
            'name' => 'P291 Retail',
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $this->assertFalse($groups->contains('key', 'treasury'));
        $this->actingAs($actor)->get(route('admin.treasury.master.index'))->assertForbidden();
    }

    public function test_arabic_and_english_use_the_same_group_and_preserve_route_identity(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');

        foreach ([
            'ar' => 'الخزينة والبنوك',
            'en' => 'Treasury & Banking',
        ] as $locale => $expectedLabel) {
            app()->setLocale($locale);
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor))->keyBy('key');

            $this->assertSame($expectedLabel, __('admin.nav_groups.treasury'));
            $this->assertTrue($groups->has('treasury'));
            $this->assertSame(
                'admin.nav_groups.treasury',
                $groups->get('treasury')['label'],
            );
        }
    }

    private function globalUser(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}
