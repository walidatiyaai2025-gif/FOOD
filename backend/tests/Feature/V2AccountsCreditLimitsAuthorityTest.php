<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\B2bAccount;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P191: account visibility does not confer credit mutation authority.
 */
final class V2AccountsCreditLimitsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_account_viewer_can_navigate_but_not_change_credit_limit_in_both_locales(): void
    {
        [$platform, $account] = $this->linkedWholesaleAccount();
        $viewer = $this->globalRole('B2B_ACCOUNT_MANAGER');

        $this->assertTrue($viewer->hasPermission('b2b.accounts.view'));
        $this->assertFalse($viewer->hasPermission('finance.manage'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $group = collect(app(AdminNavigation::class)->groupsFor($viewer->fresh()))
                ->firstWhere('key', 'accounts');
            $this->assertIsArray($group);
            $items = collect($group['children'])->where('key', 'customer_360');
            $this->assertCount(1, $items);
            $item = $items->first();
            $this->assertSame('admin.customer-360.index', $item['route']);
            $this->assertSame('admin.customer_360', $item['label']);

            $this->actingAs($viewer->fresh())
                ->get(route($item['route']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.customer_360'));

            $this->actingAs($viewer->fresh())
                ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
                ->assertOk()
                ->assertSee(__('customer_360.finance.credit_limit'));

            $this->actingAs($viewer->fresh())
                ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                    'credit_limit' => '910.123',
                ])
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'customer360.credit_limit_updated')
            ->where('auditable_id', $account->id)
            ->count());
        $this->assertEquals(0, (float) $account->fresh()->credit_limit);
    }

    public function test_authorized_wholesale_finance_manager_updates_credit_with_audit_but_invalid_values_fail(): void
    {
        [$platform, $account] = $this->linkedWholesaleAccount();
        $manager = $this->globalRole('B2B_ADMIN');

        $this->assertTrue($manager->hasPermission('finance.manage'));

        $this->actingAs($manager)
            ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                'credit_limit' => '123.456',
            ])
            ->assertRedirect(route('admin.customer-360.show', ['platformCustomer' => $platform->id]).'#finance');

        $this->assertDatabaseHas('b2b_accounts', ['id' => $account->id, 'credit_limit' => '123.456']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer360.credit_limit_updated',
            'auditable_id' => $account->id,
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                'credit_limit' => '-1',
            ])
            ->assertSessionHasErrors('credit_limit');
        $this->assertDatabaseHas('b2b_accounts', ['id' => $account->id, 'credit_limit' => '123.456']);
    }

    public function test_retail_finance_role_cannot_edit_wholesale_credit_or_access_foreign_store(): void
    {
        [$ownedPlatform, $account, $ownedStoreId] = $this->linkedWholesaleAccount('P191-OWNED');
        [$foreignPlatform] = $this->linkedWholesaleAccount('P191-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->storeRoleAssignments()->create([
            'store_id' => $ownedStoreId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        $this->assertTrue($actor->hasPermission('finance.manage', $ownedStoreId));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', ['platformCustomer' => $ownedPlatform->id]))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $ownedPlatform->id]), [
                    'credit_limit' => '999.000',
                ])
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.show', ['platformCustomer' => $foreignPlatform->id]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $foreignPlatform->id]), [
                    'credit_limit' => '999.000',
                ])
                ->assertNotFound();
        }

        $this->assertEquals(0, (float) $account->fresh()->credit_limit);
    }

    public function test_roleless_actor_is_denied_in_sidebar_and_http(): void
    {
        [$platform] = $this->linkedWholesaleAccount();
        $actor = User::factory()->create(['is_active' => true]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('customer_360', $keys);

        $this->actingAs($actor)
            ->get(route('admin.customer-360.index'))
            ->assertForbidden();
        $this->actingAs($actor)
            ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                'credit_limit' => '999.000',
            ])
            ->assertForbidden();
    }

    /** @return array{PlatformCustomer, B2bAccount, int} */
    private function linkedWholesaleAccount(string $storeCode = 'P191-CREDIT'): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = User::factory()->create(['is_active' => true]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $domain = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($storeId),
            $tierId,
            $owner,
        );
        $platform = PlatformCustomer::query()->where('user_id', $owner->id)->firstOrFail();
        $account = B2bAccount::query()->where('b2b_customer_id', $domain->id)->firstOrFail();

        return [$platform, $account, $storeId];
    }

    private function globalRole(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor;
    }
}
