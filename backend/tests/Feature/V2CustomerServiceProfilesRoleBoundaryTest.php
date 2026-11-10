<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * V2 T087R02: customer-service profile permissions are authoritative HTTP
 * boundaries; seeing Customer 360 does not grant unrestricted identity edits.
 */
final class V2CustomerServiceProfilesRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_agent_can_read_localized_profile_and_edit_credentials_but_not_name_or_phone(): void
    {
        $customer = $this->registerCustomer(
            app(WholesalePrincipal::class)->storeId(),
            'V2 2822 Wholesale Buyer',
            'v2-2822-buyer@example.test',
        );
        $actor = $this->globalUser('CUSTOMER_SERVICE_AGENT');

        $this->assertTrue($actor->hasPermission('customers.view'));
        $this->assertFalse($actor->hasPermission('customers.edit'));
        $this->assertFalse($actor->hasPermission('finance.manage'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)
                ->get(route('admin.customer-360.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $customer->id]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('canManageIdentity', true)
                ->assertViewHas('canManageFullIdentity', false);
        }

        $actor->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($actor)
            ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $customer->id]), [
                'name' => 'Forged Full Name',
                'email' => 'v2-2822-verified@example.test',
                'phone' => '+96559999999',
                'password' => 'LimitedChange123!',
                'password_confirmation' => 'LimitedChange123!',
            ])
            ->assertRedirect(route('admin.customer-360.show', ['platformCustomer' => $customer->id]).'#identity')
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('V2 2822 Wholesale Buyer', $customer->name);
        $this->assertSame('+96550000001', $customer->phone);
        $this->assertSame('v2-2822-verified@example.test', $customer->email);

        $customerUser = User::query()->findOrFail($customer->user_id);
        $this->assertSame('V2 2822 Wholesale Buyer', $customerUser->name);
        $this->assertSame('v2-2822-verified@example.test', $customerUser->email);
        $this->assertTrue(Hash::check('LimitedChange123!', $customerUser->password));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer360.identity_updated',
            'user_id' => $actor->id,
            'auditable_id' => $customer->id,
        ]);
    }

    public function test_wholesale_agent_cannot_read_or_modify_retail_only_profile_by_direct_url(): void
    {
        $retailStore = $this->store('B2C', 'V2-2822-RETAIL');
        $retailCustomer = $this->registerCustomer(
            $retailStore,
            'V2 2822 Retail Buyer',
            'v2-2822-retail@example.test',
        );

        // PlatformCustomerService intentionally provisions a wholesale identity
        // for every new platform registration, even when it originates in B2C.
        // A retail-origin platform user with that B2B identity is legitimately
        // visible to wholesale Customer Service. Model a legacy B2C-only record
        // instead: it has a valid retail membership but no wholesale authority.
        $b2bIds = DB::table('b2b_customers')
            ->where('user_id', $retailCustomer->user_id)
            ->pluck('id');
        $this->assertNotEmpty($b2bIds->all());
        DB::table('b2b_accounts')->whereIn('b2b_customer_id', $b2bIds->all())->delete();
        DB::table('b2b_customers')->whereIn('id', $b2bIds->all())->delete();
        $this->assertDatabaseMissing('b2b_customers', [
            'user_id' => $retailCustomer->user_id,
        ]);
        $this->assertDatabaseHas('b2c_customers', [
            'user_id' => $retailCustomer->user_id,
            'store_id' => $retailStore,
        ]);

        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $agent->forceFill(['locale' => $locale])->save();

            $this->actingAs($agent)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $retailCustomer->id]))
                ->assertNotFound();

            $this->actingAs($agent)
                ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $retailCustomer->id]), [
                    'email' => 'cross-channel-forbidden@example.test',
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseHas('platform_customers', [
            'id' => $retailCustomer->id,
            'name' => 'V2 2822 Retail Buyer',
            'email' => 'v2-2822-retail@example.test',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'customer360.identity_updated',
            'auditable_id' => $retailCustomer->id,
        ]);
    }

    public function test_store_scoped_retail_support_can_view_own_profile_but_cannot_take_over_credentials(): void
    {
        $ownStore = $this->store('B2C', 'V2-2822-OWN');
        $foreignStore = $this->store('B2C', 'V2-2822-FOREIGN');
        $ownCustomer = $this->registerCustomer($ownStore, 'V2 Own Buyer', 'v2-2822-own@example.test');
        $foreignCustomer = $this->registerCustomer($foreignStore, 'V2 Foreign Buyer', 'v2-2822-foreign@example.test');
        $retailAgent = $this->storeUser('RETAIL_CUSTOMER_SUPPORT', $ownStore);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retailAgent->forceFill(['locale' => $locale])->save();

            $this->actingAs($retailAgent)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $ownCustomer->id]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('canManageIdentity', false);

            $this->actingAs($retailAgent)
                ->get(route('admin.customer-360.show', ['platformCustomer' => $foreignCustomer->id]))
                ->assertNotFound();

            $this->actingAs($retailAgent)
                ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $ownCustomer->id]), [
                    'email' => 'forged-retail-owner@example.test',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('platform_customers', [
            'id' => $ownCustomer->id,
            'email' => 'v2-2822-own@example.test',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'customer360.identity_updated',
            'auditable_id' => $ownCustomer->id,
        ]);
    }

    public function test_explicit_view_deny_and_roleless_actor_block_customer_360_http_mutations(): void
    {
        $customer = $this->registerCustomer(
            app(WholesalePrincipal::class)->storeId(),
            'V2 Protected Buyer',
            'v2-2822-protected@example.test',
        );
        $deniedAgent = $this->globalUser('CUSTOMER_SERVICE_AGENT');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $deniedAgent->id,
            'permission_id' => Permission::query()->where('code', 'customers.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deniedAgent->forceFill(['has_permission_overrides' => true])->save();
        $deniedAgent = $deniedAgent->fresh();

        foreach ([$deniedAgent, $roleless] as $actor) {
            $this->assertFalse($actor->hasPermission('customers.view'));

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $this->actingAs($actor)
                    ->get(route('admin.customer-360.index'))
                    ->assertForbidden();

                $this->actingAs($actor)
                    ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $customer->id]), [
                        'email' => 'forged-roleless@example.test',
                    ])
                    ->assertForbidden();
            }
        }

        $this->assertDatabaseHas('platform_customers', [
            'id' => $customer->id,
            'email' => 'v2-2822-protected@example.test',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'customer360.identity_updated',
            'auditable_id' => $customer->id,
        ]);
    }

    private function registerCustomer(int $storeId, string $name, string $email): PlatformCustomer
    {
        $user = app(PlatformCustomerService::class)->register([
            'name' => $name,
            'email' => $email,
            'phone' => '+96550000001',
            'password' => 'OriginalPassword123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ], 'dashboard');

        return PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user->fresh();
    }

    private function storeUser(string $roleCode, int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
