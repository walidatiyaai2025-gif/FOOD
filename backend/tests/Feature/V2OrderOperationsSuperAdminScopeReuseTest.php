<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\OrderOperationsController;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\EnterprisePerformanceSeeder;
use Illuminate\Database\Events\QueryExecuted;
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

        // QueryExecuted fires synchronously inside the actual Laravel SQL execution.
        // Filter by BOTH the canonical scope service and the Orders index scope
        // call stack, excluding unrelated Sidebar/store lookups on the same GET.
        $indexScopeStoreQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$indexScopeStoreQueries): void {
            if (! str_contains(strtolower($query->sql), 'store_types')) {
                return;
            }

            $fromStoreScope = false;
            $fromOrdersIndexScopes = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === OperationalTenantScope::class
                    && ($frame['function'] ?? null) === 'storeIdsForChannels') {
                    $fromStoreScope = true;
                }
                if (($frame['class'] ?? null) === OrderOperationsController::class
                    && ($frame['function'] ?? null) === 'operationalScopes') {
                    $fromOrdersIndexScopes = true;
                }
            }

            if ($fromStoreScope && $fromOrdersIndexScopes) {
                $indexScopeStoreQueries++;
            }
        });

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->get('/admin/operations/orders?channel=b2c&store_id='.$storeId.'&per_page=25')
                ->assertOk()
                ->assertSee('data-v2-pending-discounts-tab', false)
                ->assertSee('data-v2-approved-discounts-tab', false);
        }

        // One executed B2C store lookup per Orders GET, not six permission-label
        // duplicates; the two authorized HTTP requests above use EN and AR.
        $this->assertSame(2, $indexScopeStoreQueries,
            'Active Super Admin must execute exactly one canonical Orders index store-scope query per request.');

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
