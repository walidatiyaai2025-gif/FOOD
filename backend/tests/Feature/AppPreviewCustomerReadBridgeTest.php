<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppPreviewCustomerReadBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_preview_reads_selected_customer_through_dedicated_get_bridge(): void
    {
        $storeId = $this->retailStore('PREVIEW-READ-A');
        $admin = $this->storeAdmin($storeId, 'preview-read-admin@example.test');
        $customer = $this->user('Preview Read Customer', 'preview-read-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        $token = $this->customerPreviewToken($admin, $customer, $storeId);

        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertOk()
            ->assertJsonPath('name', 'Preview Read Customer')
            ->assertJsonPath('email', 'preview-read-customer@example.test');
    }

    public function test_retail_preview_rejects_cross_store_scope_even_when_caller_tampers_query(): void
    {
        $storeA = $this->retailStore('PREVIEW-READ-A');
        $storeB = $this->retailStore('PREVIEW-READ-B');
        $admin = $this->storeAdmin($storeA, 'preview-scope-admin@example.test');
        $customer = $this->user('Preview Scope Customer', 'preview-scope-customer@example.test');
        $this->retailCustomer($customer, $storeA);

        $token = $this->customerPreviewToken($admin, $customer, $storeA);

        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson("/api/v1/app-preview/customer/profile?store_id={$storeB}")
            ->assertNotFound();
    }

    public function test_customer_preview_prefix_exposes_no_profile_mutation_route(): void
    {
        $storeId = $this->retailStore('PREVIEW-READ-ONLY');
        $admin = $this->storeAdmin($storeId, 'preview-readonly-admin@example.test');
        $customer = $this->user('Preview Readonly Customer', 'preview-readonly-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        $token = $this->customerPreviewToken($admin, $customer, $storeId);

        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->patchJson('/api/v1/app-preview/customer/profile', ['name' => 'Mutated'])
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'name' => 'Preview Readonly Customer',
        ]);
    }

    private function customerPreviewToken(User $admin, User $customer, int $storeId): string
    {
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        return (string) $response->json('preview_token');
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Preview Admin', $email);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function retailCustomer(User $user, int $storeId): void
    {
        DB::table('b2c_customers')->insert([
            'legacy_customer_id' => null,
            'store_id' => $storeId,
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => null,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
