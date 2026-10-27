<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Search\GlobalSearchProvider;
use App\Enterprise\Search\GlobalSearchRegistry;
use App\Models\Customer;
use App\Models\EnterpriseOrganization;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EnterpriseGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_order_search_is_bounded_and_cannot_cross_store_scope(): void
    {
        $allowedStore = $this->store('GLOBAL-A', 'Allowed Branch');
        $deniedStore = $this->store('GLOBAL-B', 'Denied Branch');
        $actor = $this->storeOperator($allowedStore);

        $denied = $this->order($deniedStore, 'GLOBAL-SEARCH-DENIED');

        for ($index = 0; $index < 5; $index++) {
            $this->order($allowedStore, 'GLOBAL-SEARCH-MORE-'.$index);
        }

        $allowed = $this->order($allowedStore, 'GLOBAL-SEARCH-ALLOWED');

        $response = $this->actingAs($actor)
            ->getJson(route('admin.global-search', ['q' => 'GLOBAL-SEARCH', 'limit' => 3]))
            ->assertOk()
            ->assertJsonPath('meta.limit_per_category', 3)
            ->assertJsonCount(3, 'data');

        $urls = collect($response->json('data'))->pluck('url')->all();
        $this->assertTrue(collect($urls)->every(
            static fn (string $url): bool => str_contains($url, 'store_id='.$allowedStore->id),
        ));
        $this->assertFalse(collect($urls)->contains(
            static fn (string $url): bool => str_contains($url, 'order='.$denied->id),
        ));

        $this->actingAs($actor)
            ->getJson(route('admin.global-search', ['q' => 'GLOBAL-SEARCH-ALLOWED', 'limit' => 3]))
            ->assertOk()
            ->assertJsonFragment([
                'url' => route('admin.operations.orders.index', [
                    'channel' => 'b2c',
                    'store_id' => $allowedStore->id,
                    'order' => $allowed->id,
                ], false),
            ]);
    }

    public function test_organization_results_require_platform_authorization_and_are_exact_links(): void
    {
        $store = $this->store('GLOBAL-C', 'Scoped Branch');
        $actor = $this->storeOperator($store);
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'global_search_org',
            'name' => 'Global Search Holdings',
            'is_active' => true,
        ]);

        $this->actingAs($actor)
            ->getJson(route('admin.global-search', ['q' => 'Global Search']))
            ->assertOk()
            ->assertJsonMissing(['type' => 'organization']);

        $admin = $this->globalUser('SUPER_ADMIN', 'global-search-owner@example.test');

        $this->actingAs($admin)
            ->getJson(route('admin.global-search', ['q' => 'Global Search']))
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'organization',
                'title' => 'Global Search Holdings',
                'url' => route('admin.enterprise-organizations.show', $organization, false),
            ]);
    }

    public function test_disabled_module_provider_factory_is_never_resolved(): void
    {
        app(ModuleRegistry::class)->syncManifest('sample_optional');
        $resolved = false;

        $registry = new GlobalSearchRegistry(
            app(ModuleRegistry::class),
            app(ModuleEntitlementResolver::class),
        );
        $registry->registerModule(
            'sample',
            'sample_optional',
            static fn (User $actor): EntitlementScope => EntitlementScope::platform(),
            function () use (&$resolved): GlobalSearchProvider {
                $resolved = true;

                return new class implements GlobalSearchProvider
                {
                    public function search(User $actor, string $query, int $limit): array
                    {
                        return [];
                    }
                };
            },
        );

        $registry->search($this->globalUser('SUPER_ADMIN', 'global-search-module@example.test'), 'sample');

        $this->assertFalse($resolved);
    }

    public function test_dashboard_shell_exposes_bilingual_debounced_global_search(): void
    {
        $admin = $this->globalUser('B2B_ADMIN', 'global-search-ui@example.test');

        $response = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-foodex-dashboard-header-search', false)
            ->assertSee('data-foodex-global-search', false)
            ->assertSee('Search orders and organizations');

        $html = (string) $response->getContent();
        $sidebarStart = strpos($html, '<aside class="sidebar shell-sidebar">');
        $mainStart = strpos($html, '<main class="main">');
        $this->assertNotFalse($sidebarStart);
        $this->assertNotFalse($mainStart);
        $this->assertStringNotContainsString(
            'data-foodex-global-search',
            substr($html, (int) $sidebarStart, (int) $mainStart - (int) $sidebarStart),
        );

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('ابحث في الطلبات والمؤسسات');
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeOperator(Store $store): User
    {
        $user = User::query()->create([
            'name' => 'Scoped Search Operator',
            'email' => 'global-search-scoped-'.$store->id.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    private function store(string $code, string $name): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function order(Store $store, string $number): Order
    {
        $customer = Customer::query()->create([
            'type' => 'b2c',
            'name' => 'Global Search Customer',
        ]);

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'grand_total' => 1,
        ]);
    }
}
