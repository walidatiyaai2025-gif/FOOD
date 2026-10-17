<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\RestaurantSiteResolver;
use App\Domain\SmartOrdering\RuntimeState;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmartOrderingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('smart_ordering.base_domain', 'foodex.com');
        config()->set('smart_ordering.internal_key', 'internal-secret');
        config()->set('smart_ordering.pilot_key', 'pilot-secret');
    }

    public function test_new_restaurant_is_provisioned_fail_closed_with_every_module_off(): void
    {
        $storeId = $this->retailStore('SO-OFF', false);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId);
        $runtime = app(SmartOrderingRuntimePolicy::class);

        $this->assertFalse($site->master_enabled);
        $this->assertSame(RuntimeState::DISABLED->value, $site->runtime_state);
        $this->assertSame(RuntimeState::DISABLED, $runtime->effectiveState($site));
        $this->assertSame(
            count(Module::cases()),
            DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->id)
                ->where('scope_key', 'site')
                ->where('enabled', false)
                ->count(),
        );
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));
        $this->assertNotNull(app(SmartOrderingRevisionService::class)->published($site));
    }

    public function test_host_slug_reserved_duplicate_and_safe_rename_contracts(): void
    {
        $storeA = $this->retailStore('FOODEX CAFE');
        $storeB = $this->retailStore('SECOND CAFE');
        $adminA = $this->storeAdmin($storeA);
        $adminB = $this->storeAdmin($storeB);
        $service = app(SmartOrderingSiteService::class);
        $resolver = app(RestaurantSiteResolver::class);
        $siteA = $service->ensureForStore($storeA, $adminA);
        $siteB = $service->ensureForStore($storeB, $adminB);
        $oldSlug = (string) $siteA->slug;

        $this->assertSame($siteA->id, $resolver->fromSlug($siteA->slug)?->id);
        $this->assertSame($siteA->id, $resolver->fromHost($siteA->slug.'.foodex.com')?->id);
        $this->assertNull($resolver->fromSlug('admin'));
        $this->assertNull($resolver->fromHost('unknown.foodex.com'));
        $this->assertNull($resolver->fromHost('nested.'.$siteA->slug.'.foodex.com'));

        try {
            $service->renameSlug($adminA, $siteA, 'admin');
            $this->fail('Reserved slug must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $siteA = $service->renameSlug($adminA, $siteA->fresh(), 'fresh-menu');
        $this->assertSame($siteA->id, $resolver->fromSlug($oldSlug)?->id);
        $this->assertSame('fresh-menu', $resolver->fromHost('fresh-menu.foodex.com')?->slug);

        try {
            $service->renameSlug($adminB, $siteB, 'fresh-menu');
            $this->fail('Duplicate canonical slug must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        try {
            $service->renameSlug($adminB, $siteB->fresh(), $oldSlug);
            $this->fail('Retained alias must not be claimable by another restaurant.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_all_runtime_states_follow_one_fail_closed_policy(): void
    {
        $storeId = $this->retailStore('SO-RUNTIME');
        $admin = $this->storeAdmin($storeId);
        $service = app(SmartOrderingSiteService::class);
        $runtime = app(SmartOrderingRuntimePolicy::class);
        $site = $service->ensureForStore($storeId, $admin);
        $service->setEntitlement($admin, $site, Module::PUBLIC_MENU, true);

        $site = $service->updateRuntime($admin, $site->fresh(), RuntimeState::DISABLED, true);
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));

        $site = $service->updateRuntime($admin, $site, RuntimeState::INTERNAL_TEST, true);
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));
        $internalRequest = Request::create('/');
        $internalRequest->headers->set('X-FOODEX-Smart-Ordering-Internal', 'internal-secret');
        $this->assertTrue($runtime->allowsRequest($site, Module::PUBLIC_MENU, $internalRequest));

        $site = $service->updateRuntime($admin, $site, RuntimeState::PILOT, true);
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));
        $pilotRequest = Request::create('/');
        $pilotRequest->headers->set('X-FOODEX-Smart-Ordering-Pilot', 'pilot-secret');
        $this->assertTrue($runtime->allowsRequest($site, Module::PUBLIC_MENU, $pilotRequest));

        $site = $service->updateRuntime($admin, $site, RuntimeState::ENABLED, true);
        $this->assertTrue($runtime->allowsPublic($site, Module::PUBLIC_MENU));

        $site = $service->updateRuntime($admin, $site, RuntimeState::EMERGENCY_STOP, true);
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));

        $site = $service->updateRuntime($admin, $site, RuntimeState::ENABLED, false);
        $this->assertSame(RuntimeState::DISABLED, $runtime->effectiveState($site));
        $this->assertFalse($runtime->allowsPublic($site, Module::PUBLIC_MENU));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.runtime.updated',
            'store_id' => $storeId,
            'user_id' => $admin->id,
        ]);
    }

    public function test_entitlement_inherits_from_site_and_branch_override_is_tenant_scoped(): void
    {
        $storeA = $this->retailStore('SO-BRANCH-A');
        $storeB = $this->retailStore('SO-BRANCH-B');
        $adminA = $this->storeAdmin($storeA);
        $adminB = $this->storeAdmin($storeB);
        $service = app(SmartOrderingSiteService::class);
        $runtime = app(SmartOrderingRuntimePolicy::class);
        $resolver = app(RestaurantSiteResolver::class);
        $siteA = $service->ensureForStore($storeA, $adminA);
        $siteB = $service->ensureForStore($storeB, $adminB);
        $branchA = $this->branch($siteA->id, 'A-1');
        $branchB = $this->branch($siteB->id, 'B-1');

        $service->setEntitlement($adminA, $siteA, Module::PUBLIC_MENU, true);
        $this->assertTrue($runtime->entitled($siteA->fresh(), Module::PUBLIC_MENU, $branchA));

        $service->setEntitlement($adminA, $siteA, Module::PUBLIC_MENU, false, $branchA);
        $this->assertFalse($runtime->entitled($siteA->fresh(), Module::PUBLIC_MENU, $branchA));
        $this->assertTrue($runtime->entitled($siteA->fresh(), Module::PUBLIC_MENU));

        $this->assertSame($branchA->id, $resolver->branch($siteA, $branchA->public_id)->id);

        try {
            $resolver->branch($siteA, $branchB->public_id);
            $this->fail('Cross-tenant branch resolution must fail.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $service->setEntitlement($adminA, $siteA, Module::PUBLIC_MENU, true, $branchB);
            $this->fail('Cross-tenant branch override must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_cross_tenant_admin_cannot_mutate_another_restaurant(): void
    {
        $storeA = $this->retailStore('SO-A');
        $storeB = $this->retailStore('SO-B');
        $adminA = $this->storeAdmin($storeA);
        $siteB = app(SmartOrderingSiteService::class)->ensureForStore($storeB);

        $this->actingAs($adminA)
            ->put(route('admin.smart-ordering.runtime', $siteB), [
                'runtime_state' => RuntimeState::ENABLED->value,
                'master_enabled' => 1,
            ])->assertNotFound();

        $this->assertSame(RuntimeState::DISABLED->value, $siteB->fresh()->runtime_state);
    }

    public function test_draft_isolation_atomic_publish_audit_and_public_identifier_guard(): void
    {
        $storeId = $this->retailStore('SO-REV');
        $admin = $this->storeAdmin($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);
        $revisions = app(SmartOrderingRevisionService::class);
        $before = $revisions->published($site);
        $this->assertNotNull($before);

        $draft = $revisions->createOrReuseDraft($admin, $site);
        $draft = $revisions->updateDraft($admin, $draft, [
            'site' => ['name_en' => 'Draft only', 'slug' => (string) $site->slug],
        ]);

        $this->assertSame($before->id, $revisions->published($site)?->id);
        $this->assertNotSame('Draft only', data_get($revisions->published($site)?->payload, 'site.name_en'));

        try {
            $revisions->updateDraft($admin, $draft->fresh(), [
                'site' => ['store_id' => $storeId],
            ]);
            $this->fail('Sequential internal tenant IDs must not enter a public revision payload.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $published = $revisions->publish($admin, $draft->fresh());
        $this->assertSame('Draft only', data_get($published->payload, 'site.name_en'));
        $this->assertSame(
            1,
            DB::table('smart_ordering_revisions')
                ->where('site_id', $site->id)
                ->where('status', 'published')
                ->count(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.revision.published',
            'store_id' => $storeId,
            'user_id' => $admin->id,
        ]);
    }

    public function test_public_host_boundary_returns_only_opaque_identifiers_and_same_404_for_closed_sites(): void
    {
        $storeId = $this->retailStore('SO-PUBLIC');
        $admin = $this->storeAdmin($storeId);
        $service = app(SmartOrderingSiteService::class);
        $site = $service->ensureForStore($storeId, $admin);

        $this->getJson('https://'.$site->slug.'.foodex.com/api/v1/smart-ordering/context')
            ->assertNotFound();

        $this->getJson('https://unknown.foodex.com/api/v1/smart-ordering/context')
            ->assertNotFound();

        $service->setEntitlement($admin, $site, Module::PUBLIC_MENU, true);
        $site = $service->updateRuntime($admin, $site->fresh(), RuntimeState::ENABLED, true);
        $branch = $this->branch($site->id, 'PUBLIC-BRANCH');
        $this->assertTrue(Str::isUuid((string) $branch->public_id));
        $this->assertSame(
            $site->id,
            app(RestaurantSiteResolver::class)->fromHost($site->slug.'.foodex.com')?->id,
        );
        $this->assertTrue(
            app(SmartOrderingRuntimePolicy::class)->entitled($site, Module::PUBLIC_MENU, $branch),
        );

        $this->getJson('https://'.$site->slug.'.foodex.com/api/v1/smart-ordering/context')
            ->assertOk();

        $response = $this->getJson(
            'https://'.$site->slug.'.foodex.com/api/v1/smart-ordering/context?branch='.$branch->public_id,
        )
            ->assertOk()
            ->assertJsonPath('site.id', $site->public_id)
            ->assertJsonPath('site.slug', $site->slug)
            ->assertJsonPath('selected_branch.id', $branch->public_id)
            ->assertJsonPath('site.runtime_state', RuntimeState::ENABLED->value);

        $json = $response->getContent();
        $this->assertIsString($json);
        $this->assertStringNotContainsString('"store_id"', $json);
        $this->assertStringNotContainsString('"site_id"', $json);
        $this->assertStringNotContainsString('"branch_id"', $json);
        $this->assertStringNotContainsString('"id":'.$storeId, $json);
    }

    public function test_custom_domain_verification_is_hashed_entitled_and_audited(): void
    {
        $storeId = $this->retailStore('SO-DOMAIN');
        $admin = $this->storeAdmin($storeId);
        $service = app(SmartOrderingSiteService::class);
        $runtime = app(SmartOrderingRuntimePolicy::class);
        $site = $service->ensureForStore($storeId, $admin);

        $result = $service->updateCustomDomain($admin, $site, 'orders.example.test');
        $site = $result['site'];
        $token = $result['verification_token'];
        $this->assertIsString($token);
        $this->assertNotSame($token, $site->custom_domain_verification_token_hash);

        try {
            $service->verifyCustomDomain($admin, $site, 'wrong-token');
            $this->fail('Invalid custom-domain verification token must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $site = $service->verifyCustomDomain($admin, $site->fresh(), $token);
        $this->assertNotNull($site->custom_domain_verified_at);
        $this->assertNull($runtime->resolveByHost('orders.example.test'));

        $service->setEntitlement($admin, $site, Module::CUSTOM_DOMAIN, true);
        $this->assertSame($site->id, $runtime->resolveByHost('orders.example.test')?->id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.custom_domain.verified',
            'store_id' => $storeId,
            'user_id' => $admin->id,
        ]);
    }

    private function retailStore(string $code, bool $enableRestaurantSaas = true): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($enableRestaurantSaas) {
            $this->enableRestaurantSaasForTestStore($storeId);
        }

        return $storeId;
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Admin',
            'email' => 'so-'.$storeId.'-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
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

    private function branch(int $siteId, string $code): SmartOrderingBranch
    {
        return SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => $code,
            'name_ar' => null,
            'name_en' => $code,
            'timezone' => 'Asia/Kuwait',
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
