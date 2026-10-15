<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\RestaurantSiteResolver;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingRuntimePolicy;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SmartOrderingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_new_site_is_fail_closed_with_every_entitlement_disabled(): void
    {
        $storeId = $this->retailStore('SO-OFF');
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId);

        $this->assertFalse($site->master_enabled);
        $this->assertSame('DISABLED', $site->runtime_state);
        $this->assertSame(
            count((array) config('smart_ordering.entitlements')),
            DB::table('smart_ordering_entitlements')->where('site_id', $site->id)->where('enabled', false)->count(),
        );
        $this->assertFalse(app(SmartOrderingRuntimePolicy::class)->allowsPublic($site, 'public_menu'));
    }

    public function test_slug_and_host_resolution_are_canonical_and_reserved_names_fail_closed(): void
    {
        $storeId = $this->retailStore('FOODEX CAFE');
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId);
        $resolver = app(RestaurantSiteResolver::class);

        $this->assertSame($site->id, $resolver->fromSlug($site->slug)?->id);
        $this->assertSame($site->id, $resolver->fromHost($site->slug.'.foodex.com')?->id);
        $this->assertNull($resolver->fromSlug('admin'));
        $this->assertNull($resolver->fromHost('unknown.foodex.com'));
    }

    public function test_runtime_and_entitlements_must_both_allow_public_access(): void
    {
        $storeId = $this->retailStore('SO-RUNTIME');
        $admin = $this->storeAdmin($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);
        $service = app(SmartOrderingSiteService::class);
        $runtime = app(SmartOrderingRuntimePolicy::class);

        $service->replaceEntitlements($site, ['public_menu' => true], $admin);
        $this->assertFalse($runtime->allowsPublic($site->fresh(), 'public_menu'));

        $service->updateRuntime($admin, $site->fresh(), 'ENABLED', true);
        $this->assertTrue($runtime->allowsPublic($site->fresh(), 'public_menu'));

        $service->updateRuntime($admin, $site->fresh(), 'EMERGENCY_STOP', true);
        $this->assertFalse($runtime->allowsPublic($site->fresh(), 'public_menu'));
    }

    public function test_reserved_slug_is_rejected_and_old_slug_becomes_alias(): void
    {
        $storeId = $this->retailStore('SO-SLUG');
        $admin = $this->storeAdmin($storeId);
        $service = app(SmartOrderingSiteService::class);
        $site = $service->ensureForStore($storeId, $admin);
        $old = $site->slug;

        try {
            $service->renameSlug($admin, $site, 'admin');
            $this->fail('Reserved slug must fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $renamed = $service->renameSlug($admin, $site->fresh(), 'fresh-menu');
        $this->assertSame('fresh-menu', $renamed->slug);
        $this->assertSame($renamed->id, app(RestaurantSiteResolver::class)->fromSlug($old)?->id);
    }

    public function test_draft_changes_are_not_public_until_publish(): void
    {
        $storeId = $this->retailStore('SO-REV');
        $admin = $this->storeAdmin($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);
        $revisions = app(SmartOrderingRevisionService::class);
        $before = $revisions->published($site);
        $this->assertNotNull($before);

        $draft = $revisions->createOrReuseDraft($admin, $site);
        $revisions->updateDraft($admin, $draft, ['site' => ['name_en' => 'Draft only']]);

        $this->assertSame($before->id, $revisions->published($site)?->id);
        $this->assertNotSame('Draft only', data_get($revisions->published($site)?->payload, 'site.name_en'));

        $published = $revisions->publish($admin, $draft->fresh());
        $this->assertSame('Draft only', data_get($published->payload, 'site.name_en'));
        $this->assertSame(1, DB::table('smart_ordering_revisions')->where('site_id', $site->id)->where('status', 'published')->count());
    }

    public function test_cross_tenant_admin_cannot_mutate_another_restaurant(): void
    {
        $storeA = $this->retailStore('SO-A');
        $storeB = $this->retailStore('SO-B');
        $adminA = $this->storeAdmin($storeA);
        $siteB = app(SmartOrderingSiteService::class)->ensureForStore($storeB);

        $this->actingAs($adminA)
            ->put(route('admin.smart-ordering.runtime', $siteB), [
                'runtime_state' => 'ENABLED',
                'master_enabled' => 1,
            ])->assertForbidden();

        $this->assertSame('DISABLED', $siteB->fresh()->runtime_state);
    }

    public function test_public_contract_uses_safe_identifiers_only(): void
    {
        $storeId = $this->retailStore('SO-PUBLIC');
        $admin = $this->storeAdmin($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);
        $service = app(SmartOrderingSiteService::class);
        $service->replaceEntitlements($site, ['public_menu' => true], $admin);
        $service->updateRuntime($admin, $site->fresh(), 'ENABLED', true);

        $response = $this->getJson('/api/v1/smart-ordering/'.$site->slug)->assertOk();
        $response->assertJsonPath('site.id', $site->public_id);
        $response->assertJsonMissing(['store_id' => $storeId]);
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Admin',
            'email' => 'so-'.$storeId.'@example.test',
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
}
