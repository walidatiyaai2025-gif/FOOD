<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppPreviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_b2b_admin_can_open_preview_center_without_fake_runtime(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'preview-b2b@example.test');

        $this->actingAs($user)
            ->get('/admin/app-preview')
            ->assertOk()
            ->assertSee('data-preview-runtime-host', false)
            ->assertSee('data-preview-runtime-contract="shared-flutter-v1"', false)
            ->assertSee('data-preview-safe-mode="read_only"', false)
            ->assertDontSee('preview_token', false)
            ->assertDontSee('X-Foodex-Preview-Token', false);
    }

    public function test_preview_center_defaults_to_customer_published_auto_launch_contract(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'preview-auto-entry@example.test');

        $html = $this->actingAs($user)
            ->get('/admin/app-preview')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-preview-entry="auto"', $html);
        $this->assertStringContainsString('data-preview-default-app="customer"', $html);
        $this->assertStringContainsString('data-preview-default-configuration="published"', $html);
        $this->assertStringContainsString("selectFirstDriver: app?.value === 'driver'", $html);
        $this->assertStringContainsString('restoreDriverRequired()', $html);
        $this->assertStringContainsString('/admin/b2b/drivers', $html);
        $this->assertStringContainsString("message?.metadata && typeof message.metadata === 'object'", $html);
        $this->assertStringContainsString('runtime.auth_mode ?? context.auth_mode', $html);
        $this->assertStringContainsString('runtime.channel ?? context.channel', $html);
        $this->assertStringContainsString('runtime.store_id ?? context.store_id', $html);
    }

    public function test_retail_admin_sees_only_assigned_retail_store_in_preview_center(): void
    {
        $allowedStore = $this->store('B2C', 'PREVIEW-ALLOWED');
        $otherStore = $this->store('B2C', 'PREVIEW-OTHER');
        $user = User::query()->create([
            'name' => 'Retail Preview',
            'email' => 'preview-retail@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->assignStoreRole($user, $allowedStore, 'B2C_STORE_ADMIN');

        $this->actingAs($user)
            ->get('/admin/app-preview')
            ->assertOk()
            ->assertSee('PREVIEW-ALLOWED')
            ->assertDontSee('PREVIEW-OTHER');

        $this->assertNotSame($allowedStore, $otherStore);
    }

    public function test_user_without_preview_permission_is_denied_and_navigation_hides_preview(): void
    {
        $user = User::query()->create([
            'name' => 'No Preview',
            'email' => 'no-preview@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/admin/app-preview')->assertForbidden();

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertFalse($items->contains('app_preview'));
    }

    public function test_authorized_navigation_contains_single_preview_center_entry(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'preview-owner@example.test');

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->where('key', 'app_preview')
            ->values();

        $this->assertCount(1, $items);
        $this->assertSame('admin.app-preview.index', $items->first()['route']);
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

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
