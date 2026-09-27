<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\StoreContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2c_store_admin_resolves_only_assigned_store(): void
    {
        $storeA = $this->createStore('B2C', 'TENANT-A');
        $storeB = $this->createStore('B2C', 'TENANT-B');
        $user = $this->user('tenant-a@example.test');
        $this->assignStoreRole($user, $storeA, 'B2C_STORE_ADMIN');

        $resolver = app(TenantContextResolver::class);

        $context = $resolver->retail($user, $storeA);

        $this->assertSame('b2c', $context->channel);
        $this->assertSame($storeA, $context->storeId);
        $this->assertFalse($context->supportAccess);
        $this->assertSame([$storeA], $resolver->retailStoreIds($user));

        try {
            $resolver->retail($user, $storeB);
            $this->fail('Cross-store access must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_b2b_admin_cannot_enter_retail_context(): void
    {
        $store = $this->createStore('B2C', 'TENANT-RETAIL');
        $user = $this->userWithGlobalRole('B2B_ADMIN', 'b2b-admin@example.test');

        try {
            app(TenantContextResolver::class)->retail($user, $store);
            $this->fail('B2B admin must not enter retail tenant context.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $wholesale = app(TenantContextResolver::class)->wholesale($user);
        $this->assertTrue($wholesale->isWholesale());
        $this->assertNull($wholesale->storeId);
    }

    public function test_super_admin_retail_support_access_must_be_explicit_and_is_audited(): void
    {
        $store = $this->createStore('B2C', 'TENANT-SUPPORT');
        $user = $this->userWithGlobalRole('SUPER_ADMIN', 'owner@example.test');
        $resolver = app(TenantContextResolver::class);

        try {
            $resolver->retail($user, $store);
            $this->fail('SUPER_ADMIN retail access must be explicit.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $context = $resolver->retail($user, $store, true);

        $this->assertTrue($context->supportAccess);
        $this->assertSame($store, $context->storeId);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $store,
        ]);
    }

    public function test_retail_context_rejects_wholesale_store_and_wholesale_context_rejects_retail_store(): void
    {
        $retail = $this->createStore('B2C', 'TENANT-R');
        $wholesale = $this->createStore('B2B', 'WHOLESALE-W');
        $owner = $this->userWithGlobalRole('SUPER_ADMIN', 'owner-channel@example.test');
        $resolver = app(TenantContextResolver::class);

        try {
            $resolver->retail($owner, $wholesale, true);
            $this->fail('Retail context must reject wholesale store.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        try {
            $resolver->wholesale($owner, $retail);
            $this->fail('Wholesale context must reject retail store.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_default_retail_store_is_only_selected_for_one_authorized_store(): void
    {
        $storeA = $this->createStore('B2C', 'TENANT-ONE');
        $storeB = $this->createStore('B2C', 'TENANT-TWO');
        $user = $this->user('multi-store@example.test');
        $resolver = app(TenantContextResolver::class);

        $this->assignStoreRole($user, $storeA, 'B2C_STORE_ADMIN');
        $this->assertSame($storeA, $resolver->defaultRetailStoreId($user));

        $this->assignStoreRole($user, $storeB, 'B2C_STORE_ADMIN');
        $this->assertNull($resolver->defaultRetailStoreId($user));
    }

    public function test_store_context_holder_is_request_scoped_state(): void
    {
        $holder = app(StoreContext::class);
        $store = $this->createStore('B2C', 'TENANT-HOLDER');
        $user = $this->user('holder@example.test');
        $this->assignStoreRole($user, $store, 'B2C_STORE_ADMIN');

        $holder->set(app(TenantContextResolver::class)->retail($user, $store));

        $this->assertSame($store, $holder->storeId());
        $this->assertTrue($holder->require()->isRetail());

        $holder->clear();
        $this->assertNull($holder->current());
    }

    private function createStore(string $typeCode, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $typeCode)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function userWithGlobalRole(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function assignStoreRole(User $user, int $storeId, string $roleCode): void
    {
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
