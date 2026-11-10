<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\V2SidebarItemStatus;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarItemStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_trusted_item_status_is_localized_once_on_authorized_link_for_both_locales(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');

        foreach (['new', 'paused', 'beta', 'approval_required'] as $status) {
            config()->set('admin_sidebar.items.b2b_dashboard.status', $status);

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();

                $html = (string) $this->actingAs($actor->fresh())
                    ->get(route('admin.b2b.dashboard'))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->getContent();

                $this->assertStringContainsString(
                    'data-status-item="b2b_dashboard" data-item-status="'.$status.'" aria-label="'.__('sidebar_item_status.'.$status).'"',
                    $html,
                );
                $this->assertSame(1, substr_count($html, 'data-status-item="b2b_dashboard"'));
                $this->assertStringContainsString('data-nav-item="b2b_dashboard"', $html);
                $this->assertStringContainsString('class="foodex-nav-label"', $html);
            }
        }
    }

    public function test_missing_invalid_and_unregistered_item_statuses_are_suppressed(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');
        $resolver = app(V2SidebarItemStatus::class);
        $item = ['key' => 'b2b_dashboard', 'route' => 'admin.b2b.dashboard'];

        $this->assertNull($resolver->forAuthorizedItem($item + ['status' => 'beta']));
        $this->actingAs($actor)->get(route('admin.b2b.dashboard'))
            ->assertOk()->assertDontSee('data-status-item=', false);

        foreach (['unknown', '<script>alert(1)</script>', '', null, 42, ['beta']] as $invalid) {
            config()->set('admin_sidebar.items.b2b_dashboard.status', $invalid);
            $this->assertNull($resolver->forAuthorizedItem($item));
        }

        config()->set('admin_sidebar.items.b2b_dashboard.status', 'beta');
        $this->assertSame('beta', $resolver->forAuthorizedItem($item));
        $this->assertNull($resolver->forAuthorizedItem(['key' => 'unregistered', 'route' => 'admin.b2b.dashboard']));
        $this->assertNull($resolver->forAuthorizedItem(['key' => 'b2b_dashboard', 'route' => '']));
        $this->assertNull($resolver->forAuthorizedItem(['key' => 5, 'route' => 'admin.b2b.dashboard']));
        $this->assertNull($resolver->forAuthorizedItem(['key' => 'b2b_dashboard']));
    }

    public function test_item_badges_never_expand_channel_or_store_scope(): void
    {
        $own = $this->retailStore('V2-P373-OWN');
        $foreign = $this->retailStore('V2-P373-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('admin_sidebar.items.b2c_dashboard.status', 'paused');
        config()->set('admin_sidebar.items.b2b_dashboard.status', 'beta');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $html = (string) $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $own]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-status-item="b2c_dashboard"', $html);
            $this->assertStringNotContainsString('data-status-item="b2b_dashboard"', $html);
            $this->assertStringNotContainsString('href="'.route('admin.b2b.dashboard').'"', $html);

            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_status_metadata_never_mutates_navigation_permissions_or_inactive_deny(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');
        $before = app(AdminNavigation::class)->groupsFor($actor);
        config()->set('admin_sidebar.items.b2b_dashboard.status', 'approval_required');

        $this->assertSame($before, app(AdminNavigation::class)->groupsFor($actor->fresh()));
        $this->actingAs($actor)->get(route('admin.b2b.dashboard'))
            ->assertOk()->assertSee('data-status-item="b2b_dashboard"', false);

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $denied) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($denied->fresh()));
            $this->actingAs($denied->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
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
