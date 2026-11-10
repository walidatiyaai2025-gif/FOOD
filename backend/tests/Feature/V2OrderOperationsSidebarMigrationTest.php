<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2721 / T356R4: order operations has one existing authorized destination
 * under the correct bilingual business group, not a new grant or route.
 */
final class V2OrderOperationsSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_orders_link_uses_one_canonical_operations_group_and_route_in_both_locales(): void
    {
        $actor = $this->wholesaleAdmin();
        $this->assertTrue(Route::has('admin.operations.orders.index'));
        $this->assertTrue($actor->hasPermission('orders.view'));

        foreach ([
            'ar' => ['direction' => 'rtl', 'group' => 'الطلبات والعمليات', 'item' => 'إدارة الطلبات'],
            'en' => ['direction' => 'ltr', 'group' => 'Orders & Operations', 'item' => 'Order Management'],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $group = collect($groups)->firstWhere('key', 'operations');
            $this->assertIsArray($group);
            $this->assertSame($expected['group'], $group['label_text'] ?? __($group['label']));

            $links = collect($groups)
                ->flatMap(static fn (array $entry): array => $entry['children'])
                ->filter(static fn (array $entry): bool => $entry['key'] === 'order_operations');

            $this->assertCount(1, $links);
            $link = $links->first();
            $this->assertIsArray($link);
            $this->assertContains('order_operations', array_column($group['children'], 'key'));
            $this->assertSame('admin.operations.orders.index', $link['route']);
            $this->assertSame('orders.view', $link['permission']);
            $this->assertSame('admin.order_management', $link['label']);
            $this->assertSame($expected['item'], $link['label_text'] ?? __($link['label']));

            $this->actingAs($actor->fresh())
                ->get(route($link['route'], $link['params']))
                ->assertOk()
                ->assertSee('dir="'.$expected['direction'].'"', false);
        }
    }

    public function test_sidebar_layout_cannot_grant_order_management_to_roleless_or_inactive_actor(): void
    {
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $inactive = $this->wholesaleAdmin();
        $inactive->forceFill(['is_active' => false])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([$roleless, $inactive] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->assertNotContains(
                    'order_operations',
                    collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                        ->flatMap(static fn (array $group): array => $group['children'])
                        ->pluck('key')
                        ->all(),
                );

                $this->actingAs($actor->fresh())
                    ->get(route('admin.operations.orders.index'))
                    ->assertForbidden();
            }
        }
    }

    private function wholesaleAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }
}
