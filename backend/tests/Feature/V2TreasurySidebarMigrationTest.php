<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
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
 * T356R9: the existing Treasury master-data entry belongs in Finance & Treasury.
 * A shorter navigation label must never weaken store or finance authority.
 */
final class V2TreasurySidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_finance_treasury_destination_uses_canonical_bilingual_sidebar_name_and_real_page(): void
    {
        // A wholesale global role needs an active wholesale store in its
        // operational tenant scope, just as the live installation does.
        app(WholesalePrincipal::class)->storeId();
        $admin = $this->globalActor('B2B_ADMIN');

        foreach ([
            'ar' => ['label' => 'الخزينة', 'heading' => 'البيانات الأساسية للخزينة', 'dir' => 'rtl'],
            'en' => ['label' => 'Treasury', 'heading' => 'Treasury master data', 'dir' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($admin->fresh());
            $finance = collect($groups)->firstWhere('key', 'finance');

            $this->assertNotNull($finance);
            $items = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'treasury_master')
                ->values();

            $this->assertCount(1, $items, 'Treasury master data must retain one canonical Sidebar route.');
            $this->assertContains('treasury_master', array_column($finance['children'], 'key'));

            $item = $items->first();
            $this->assertSame('treasury.nav_title', $item['label']);
            $this->assertSame('admin.treasury.master.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertNull($item['permission'], 'Finance scope is checked before projection.');
            $this->assertSame($expected['label'], __($item['label']));
            $this->assertSame($expected['heading'], __('treasury.title'));
            $this->assertSame(__('admin.nav_groups.finance'), __($finance['label']));

            $this->actingAs($admin)
                ->get(route('admin.treasury.master.index'))
                ->assertOk()
                ->assertSee('dir="'.$expected['dir'].'"', false)
                ->assertSee($expected['heading'])
                ->assertSee('data-foodex-mobile-web-page="treasury-master"', false);
        }
    }

    public function test_retail_finance_stays_store_scoped_and_renamed_platform_link_never_grants_access(): void
    {
        $storeA = $this->retailStore('V2-2726-OWNED');
        $storeB = $this->retailStore('V2-2726-FOREIGN');
        $retail = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->assignRetailFinanceRole($retail, $storeA);
        $roleless = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            // The Retail Finance actor retains their existing scoped master-data
            // access without gaining the platform-level Finance Sidebar item.
            $retailKeys = collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('treasury_master', $retailKeys);

            $this->actingAs($retail)
                ->get(route('admin.treasury.master.index', ['store_id' => $storeA]))
                ->assertOk();

            $this->actingAs($retail)
                ->get(route('admin.treasury.master.index', ['store_id' => $storeB]))
                ->assertNotFound();

            $forgedCode = 'V2-2726-'.strtoupper($locale);
            $this->actingAs($retail)
                ->post(route('admin.treasury.cashboxes.store'), [
                    'store_id' => $storeB,
                    'code' => $forgedCode,
                    'name' => 'Foreign Treasury Cashbox',
                    'operator_visibility' => 'store',
                    'approval_visibility' => 'store',
                    'is_active' => 1,
                ])
                ->assertNotFound();

            $this->assertDatabaseMissing('enterprise_cashboxes', ['code' => $forgedCode]);

            $rolelessKeys = collect(app(AdminNavigation::class)->groupsFor($roleless->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('treasury_master', $rolelessKeys);

            $this->actingAs($roleless)
                ->get(route('admin.treasury.master.index'))
                ->assertForbidden();
        }
    }

    private function retailStore(string $code): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
        );

        return $storeId;
    }

    private function assignRetailFinanceRole(User $actor, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}
