<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T356R11: the existing App Preview destination is reused under Applications.
 * Renaming a menu item never creates a preview permission or store grant.
 */
final class V2AppPreviewSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_app_preview_link_and_page_title_are_consistent_in_arabic_and_english(): void
    {
        $admin = $this->admin('B2B_ADMIN', 'v2-2728-b2b@example.test');

        foreach ([
            'ar' => ['title' => 'معاينة التطبيق', 'direction' => 'rtl'],
            'en' => ['title' => 'Application Preview', 'direction' => 'ltr'],
        ] as $locale => $expected) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($admin->fresh());
            $applications = collect($groups)->firstWhere('key', 'applications');

            $this->assertNotNull($applications);
            $items = collect($groups)
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'app_preview')
                ->values();

            $this->assertCount(1, $items, 'Preview must use one canonical navigation destination.');
            $this->assertContains('app_preview', array_column($applications['children'], 'key'));

            $item = $items->first();
            $this->assertSame('admin.app_preview', $item['label']);
            $this->assertSame('admin.app-preview.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertNull($item['permission'], 'Scoped navigation checks authorization before projection.');
            $this->assertSame($expected['title'], __($item['label']));
            $this->assertSame($expected['title'], __('admin.preview_center.title'));

            $this->actingAs($admin)
                ->get(route('admin.app-preview.index'))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false)
                ->assertSee($expected['title'])
                ->assertSee('data-preview-runtime-contract="shared-flutter-v1"', false)
                ->assertSee('data-preview-safe-mode="read_only"', false);
        }
    }

    public function test_retail_preview_remains_store_scoped_and_roleless_user_cannot_access_it_in_both_locales(): void
    {
        $storeA = $this->store('V2-2728-ALLOWED');
        $storeB = $this->store('V2-2728-DENIED');
        $retail = $this->admin('B2C_STORE_ADMIN', 'v2-2728-retail@example.test', $storeA);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $roleless->forceFill(['locale' => $locale])->save();

            $items = collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'app_preview')
                ->values();
            $this->assertCount(1, $items);

            $this->actingAs($retail)
                ->get(route('admin.app-preview.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('V2-2728-ALLOWED')
                ->assertDontSee('V2-2728-DENIED');

            $this->actingAs($retail)
                ->getJson(route('admin.app-preview.targets', [
                    'target_type' => 'customer',
                    'channel' => 'b2c',
                    'store_id' => $storeB,
                ]))
                ->assertForbidden();

            $unprivilegedKeys = collect(app(AdminNavigation::class)->groupsFor($roleless->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('app_preview', $unprivilegedKeys);

            $this->actingAs($roleless)
                ->get(route('admin.app-preview.index'))
                ->assertForbidden();
        }
    }

    private function store(string $code): int
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

    private function admin(string $role, string $email, ?int $storeId = null): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = Role::query()->where('code', $role)->firstOrFail()->getKey();

        if ($storeId === null) {
            $user->roles()->attach($roleId);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->getKey(),
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
