<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\V2SidebarGroupStatus;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarGroupStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_each_allowlisted_status_is_localized_on_authorized_group_in_both_directions(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $this->assertNotNull(collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'overview'));

        foreach (['new', 'approval_required', 'exceptions', 'beta'] as $status) {
            config()->set('admin_sidebar.groups.overview.status', $status);
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $user->forceFill(['locale' => $locale])->save();
                $html = (string) $this->actingAs($user->fresh())
                    ->get(route('admin.b2b.dashboard'))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->getContent();

                $this->assertStringContainsString(
                    'data-status-group="overview" data-group-status="'.$status.'" aria-label="'.__('sidebar_group_status.'.$status).'"',
                    $html,
                );
                $this->assertSame(1, substr_count($html, 'data-status-group="overview"'));
                $this->assertStringContainsString('class="foodex-nav-group-title"', $html);
                $this->assertStringContainsString('class="foodex-nav-disclosure"', $html);
            }
        }
    }

    public function test_unclassified_or_invalid_metadata_never_produces_group_badge(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');
        $resolver = app(V2SidebarGroupStatus::class);
        $group = ['key' => 'overview', 'children' => [['key' => 'b2b_dashboard']]];

        $this->assertNull($resolver->forAuthorizedGroup($group + ['status' => 'beta']));
        $this->actingAs($actor)->get(route('admin.b2b.dashboard'))
            ->assertOk()->assertDontSee('data-status-group=', false);

        foreach (['unknown', '<script>alert(1)</script>', '', null, 1] as $invalid) {
            config()->set('admin_sidebar.groups.overview.status', $invalid);
            $this->assertNull($resolver->forAuthorizedGroup($group));
        }
        config()->set('admin_sidebar.groups.overview.status', 'beta');
        $this->assertNull($resolver->forAuthorizedGroup(['key' => 'overview', 'children' => []]));
        $this->assertNull($resolver->forAuthorizedGroup(['key' => 'unregistered', 'children' => [['key' => 'x']]]));
        $this->assertNull($resolver->forAuthorizedGroup(['key' => 999, 'children' => [['key' => 'x']]]));
    }

    public function test_retained_group_status_never_leaks_wholesale_or_other_retail_store_routes(): void
    {
        $own = $this->retailStore('V2-P164-OWN');
        $foreign = $this->retailStore('V2-P164-FOREIGN');
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config()->set('admin_sidebar.groups.overview.status', 'new');
        config()->set('admin_sidebar.groups.erp.status', 'beta');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $own]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-status-group="overview"', $html);
            $this->assertStringNotContainsString('data-status-group="erp"', $html);
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);
            $this->actingAs($user->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($user->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_badges_do_not_mutate_authorized_navigation_or_permissions(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $before = app(AdminNavigation::class)->groupsFor($user);
        config()->set('admin_sidebar.groups.overview.status', 'approval_required');
        config()->set('admin_sidebar.groups.catalog.status', 'exceptions');
        $this->assertSame($before, app(AdminNavigation::class)->groupsFor($user->fresh()));
        $this->assertTrue($user->hasPermission('catalog.view'));
        $this->actingAs($user)->get(route('admin.catalog.index'))->assertOk();
        $html = (string) $this->actingAs($user)
            ->get(route('admin.b2b.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-status-group="overview"', $html);
        $this->assertStringContainsString('data-status-group="catalog"', $html);
        $this->assertStringContainsString('data-authorized-page-count=', $html);

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();
        foreach ([$roleless, $inactive] as $actor) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
        }
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
