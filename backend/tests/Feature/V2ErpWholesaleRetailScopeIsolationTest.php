<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 P102: global wholesale authority is never a permission to operate
 * another retail store through an already-entered support session.
 */
final class V2ErpWholesaleRetailScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_retail_support_is_limited_to_the_selected_store_not_another_retail_scope(): void
    {
        $first = $this->retailStore('V2-P102-SUPPORT-A');
        $second = $this->retailStore('V2-P102-SUPPORT-B');
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');

        $context = app(TenantContextResolver::class)->retail($admin, $first->id, true);
        $authorizer = app(EnterpriseScopeAuthorizer::class);

        $this->assertSame('b2c', $context->channel);
        $this->assertTrue($context->supportAccess);
        $this->assertTrue($authorizer->allows($admin, $context, 'finance.manage', 'store', $first->id));
        $this->assertFalse($authorizer->allows($admin, $context, 'finance.manage', 'store', $second->id));
        $this->assertFalse($authorizer->allows($admin, $context, 'finance.manage', 'unknown', $first->id));

        try {
            $authorizer->authorize($admin, $context, 'finance.manage', 'store', $second->id);
            $this->fail('Retail support for store A must never authorize store B.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tenant.support_access.entered',
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_context_belongs_to_exactly_one_active_authenticated_actor(): void
    {
        $store = $this->retailStore('V2-P102-ACTOR');
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $other = User::factory()->create(['is_active' => true]);
        $context = app(TenantContextResolver::class)->retail($admin, $store->id, true);
        $authorizer = app(EnterpriseScopeAuthorizer::class);

        $forged = new ResolvedTenantContext(
            userId: (int) $other->getKey(),
            channel: 'b2c',
            storeId: $store->id,
            supportAccess: true,
            enterpriseScope: $context->enterpriseScope,
        );

        $this->assertFalse($authorizer->allows($admin, $forged, 'finance.manage', 'store', $store->id));
        $this->assertFalse($authorizer->allows($other, $context, 'finance.manage', 'store', $store->id));

        $admin->forceFill(['is_active' => false])->save();
        $this->assertFalse($authorizer->allows($admin->fresh(), $context, 'finance.manage', 'store', $store->id));
        $this->assertFalse($authorizer->allows($admin->fresh(), $context, 'finance.manage', 'store', 999999));

        $admin->forceFill(['is_active' => true])->save();
        $this->assertTrue($authorizer->allows($admin->fresh(), $context, 'finance.manage', 'store', $store->id));
    }

    public function test_retail_store_role_and_wholesale_role_cannot_switch_to_the_other_channel_in_ar_and_en(): void
    {
        $store = $this->retailStore('V2-P102-CHANNEL');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wholesale = $this->userWithGlobalRole('B2B_ADMIN');
        $tenants = app(TenantContextResolver::class);

        $context = $tenants->retail($retail, $store->id);
        $this->assertTrue(app(EnterpriseScopeAuthorizer::class)->allows(
            $retail,
            $context,
            'customers.view',
            'store',
            $store->id,
        ));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $wholesale->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail)
                ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $store->id]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retail)
                ->get(route('admin.b2b.module', ['module' => 'customers']))
                ->assertForbidden();
        }

        $this->assertScopeFailure(
            fn (): ResolvedTenantContext => $tenants->wholesale($retail, $store->id),
            403,
        );
        $this->assertScopeFailure(
            fn (): ResolvedTenantContext => $tenants->retail($wholesale, $store->id),
            403,
        );

        $this->assertSame(0, DB::table('user_store_roles')->where('user_id', $wholesale->id)->count());
    }

    public function test_wholesale_control_plane_access_remains_valid_but_cannot_use_a_retail_store_as_wholesale(): void
    {
        $retail = $this->retailStore('V2-P102-RETAIL');
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $principalStore = app(WholesalePrincipal::class)->storeId();
        app(OrganizationHierarchyService::class)
            ->ensureDefaultScopeForStore(Store::query()->findOrFail($principalStore));
        $tenants = app(TenantContextResolver::class);
        $context = $tenants->wholesale($admin, $principalStore);
        $authorizer = app(EnterpriseScopeAuthorizer::class);

        $this->assertSame('b2b', $context->channel);
        $this->assertTrue($authorizer->allows(
            $admin,
            $context,
            'finance.view',
            'store',
            $principalStore,
        ));
        $this->assertFalse($authorizer->allows($admin, $context, 'finance.view', 'store', $retail->id));

        $globalWholesale = $tenants->wholesale($admin);
        $this->assertTrue($authorizer->allows($admin, $globalWholesale, 'finance.view', 'store', $principalStore));
        $this->assertFalse($authorizer->allows($admin, $globalWholesale, 'finance.view', 'store', $retail->id));

        $this->assertScopeFailure(
            fn (): ResolvedTenantContext => $tenants->wholesale($admin, $retail->id),
            404,
        );
    }

    private function retailStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithGlobalRole(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    /** @param \Closure():ResolvedTenantContext $operation */
    private function assertScopeFailure(\Closure $operation, int $expected): void
    {
        try {
            $operation();
            $this->fail('Expected the tenant context resolver to reject this role/channel combination.');
        } catch (HttpException $exception) {
            $this->assertSame($expected, $exception->getStatusCode());
        }
    }
}
