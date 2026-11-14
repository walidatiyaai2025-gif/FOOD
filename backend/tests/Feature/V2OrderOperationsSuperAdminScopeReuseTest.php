<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\EnterprisePerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2OrderOperationsSuperAdminScopeReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_scope_reuse_preserves_discount_tabs_channel_separation_and_ar_en(): void
    {
        $this->seed(EnterprisePerformanceSeeder::class);

        $storeId = (int) DB::table('stores')
            ->where('code', 'like', 'PERF-%')
            ->orderBy('id')
            ->value('id');

        $this->assertGreaterThan(0, $storeId);

        $admin = User::query()->create([
            'name' => 'V2 Orders Super Admin',
            'email' => 'v2-orders-superadmin@example.test',
            'password' => 'test-only-password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->get('/admin/operations/orders?channel=b2c&store_id='.$storeId.'&per_page=25')
                ->assertOk()
                ->assertSee('data-v2-pending-discounts-tab', false)
                ->assertSee('data-v2-approved-discounts-tab', false);
        }

        // A Super Admin still cannot mix two operational channels in one list.
        $this->actingAs($admin)
            ->get('/admin/operations/orders?channel=all&per_page=25')
            ->assertForbidden();

        // The optimization must never turn permission-independent Super Admin
        // scope reuse into a bypass for a different, unprivileged actor.
        $unprivileged = User::query()->create([
            'name' => 'V2 Unprivileged Orders User',
            'email' => 'v2-orders-unprivileged@example.test',
            'password' => 'test-only-password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($unprivileged)
            ->get('/admin/operations/orders?channel=b2c&store_id='.$storeId.'&per_page=25')
            ->assertForbidden();
    }
}
