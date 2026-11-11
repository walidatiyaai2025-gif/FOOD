<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P327: Restaurant publishing is a guarded step of the existing menu
 * workspace, never a second navigation route or presentation-only permission.
 */
final class V2RestaurantPublishAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p327_bilingual_review_hides_publish_actions_for_manage_only_store_operator(): void
    {
        $denied = $this->restaurant('V2-P327-DENIED');
        $allowed = $this->restaurant('V2-P327-ALLOWED');
        $actor = $this->storeOperator([$denied, $allowed]);
        $this->denyPublish($actor, $denied);

        $this->assertTrue($actor->fresh()->hasPermission('smart_ordering.manage', $denied));
        $this->assertFalse($actor->fresh()->hasPermission('smart_ordering.publish', $denied));
        $this->assertTrue($actor->fresh()->hasPermission('smart_ordering.publish', $allowed));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.menu.index', [
                    'store_id' => $denied,
                    'review' => 1,
                    'legacy' => 1,
                ]));
            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-smart-ordering-draft-review', false)
                ->assertSee(__('smart_ordering_menu.public_menu.open'))
                ->assertDontSee('action="'.route('admin.smart-ordering.menu.unpublish').'"', false)
                ->assertDontSee('action="'.route('admin.smart-ordering.menu.schedule', $this->draft($denied)).'"', false)
                ->assertDontSee('action="'.route('admin.smart-ordering.menu.publish', $this->draft($denied)).'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.menu.index', [
                    'store_id' => $allowed,
                    'review' => 1,
                    'legacy' => 1,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('action="'.route('admin.smart-ordering.menu.publish', $this->draft($allowed)).'"', false)
                ->assertSee('action="'.route('admin.smart-ordering.menu.schedule', $this->draft($allowed)).'"', false);
        }
    }

    public function test_p327_publish_deny_blocks_all_mutations_and_preserves_both_stores(): void
    {
        $denied = $this->restaurant('V2-P327-READONLY');
        $allowed = $this->restaurant('V2-P327-WRITABLE');
        $actor = $this->storeOperator([$denied, $allowed]);

        foreach ([$denied, $allowed] as $storeId) {
            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.menu.index', ['store_id' => $storeId, 'review' => 1]))
                ->assertOk();
        }

        $deniedDraft = $this->draft($denied);
        $allowedDraft = $this->draft($allowed);
        $baseline = $this->revisionState([$denied, $allowed]);
        $this->denyPublish($actor, $denied);

        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.menu.publish', $deniedDraft), ['store_id' => $denied])
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.menu.schedule', $deniedDraft), [
                'store_id' => $denied,
                'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.menu.unpublish'), ['store_id' => $denied])
            ->assertNotFound();

        $this->assertSame($baseline, $this->revisionState([$denied, $allowed]));

        // A legitimate Store A context must not publish Store B's revision.
        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.menu.publish', $deniedDraft), ['store_id' => $allowed])
            ->assertNotFound();
        $this->assertSame('draft', $deniedDraft->fresh()->status);
        $this->assertSame('draft', $allowedDraft->fresh()->status);
        $this->assertSame($baseline, $this->revisionState([$denied, $allowed]));
    }

    public function test_p327_authorized_publisher_promotes_only_the_selected_store_draft(): void
    {
        $first = $this->restaurant('V2-P327-FIRST');
        $second = $this->restaurant('V2-P327-SECOND');
        $actor = $this->storeOperator([$first, $second]);

        foreach ([$first, $second] as $storeId) {
            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.menu.index', ['store_id' => $storeId, 'review' => 1]))
                ->assertOk();
        }

        $firstDraft = $this->draft($first);
        $secondDraft = $this->draft($second);
        $firstPublished = SmartOrderingRevision::query()
            ->where('site_id', $this->siteId($first))->where('status', 'published')->firstOrFail();
        $secondPublished = SmartOrderingRevision::query()
            ->where('site_id', $this->siteId($second))->where('status', 'published')->firstOrFail();

        $this->actingAs($actor)
            ->post(route('admin.smart-ordering.menu.publish', $firstDraft), ['store_id' => $first])
            ->assertRedirect();

        $this->assertSame('published', $firstDraft->fresh()->status);
        $this->assertSame('archived', $firstPublished->fresh()->status);
        $this->assertSame('draft', $secondDraft->fresh()->status);
        $this->assertSame('published', $secondPublished->fresh()->status);

        // A stale/already-published revision cannot be replayed.
        $this->actingAs($actor)
            ->post(route('admin.smart-ordering.menu.publish', $firstDraft), ['store_id' => $first])
            ->assertNotFound();
        $this->assertSame('published', $firstDraft->fresh()->status);
    }

    public function test_p327_unassigned_store_disabled_entitlement_and_wholesale_actor_fail_closed(): void
    {
        $owned = $this->restaurant('V2-P327-OWNED');
        $disabled = $this->restaurant('V2-P327-DISABLED');
        $foreign = $this->restaurant('V2-P327-FOREIGN');
        $actor = $this->storeOperator([$owned, $disabled]);

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $disabled, 'review' => 1]))
            ->assertOk();
        $disabledDraft = $this->draft($disabled);

        DB::table('enterprise_module_entitlements')
            ->where('module_code', 'smart_ordering')
            ->where('scope_key', 'store:'.$disabled)
            ->update(['is_enabled' => false, 'updated_at' => now()]);

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $disabled, 'review' => 1]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('admin.smart-ordering.menu.publish', $disabledDraft), ['store_id' => $disabled])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('admin.smart-ordering.menu.unpublish'), ['store_id' => $foreign])
            ->assertNotFound();
        $this->assertSame('draft', $disabledDraft->fresh()->status);

        $wholesale = User::factory()->create(['is_active' => true]);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($wholesale)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $owned, 'review' => 1]))
            ->assertForbidden();
        $this->actingAs($wholesale)
            ->post(route('admin.smart-ordering.menu.unpublish'), ['store_id' => $owned])
            ->assertForbidden();
    }

    private function restaurant(string $code): int
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        $this->enableRestaurantSaasForTestStore((int) $store->id);

        return (int) $store->id;
    }

    /** @param list<int> $storeIds */
    private function storeOperator(array $storeIds): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $storeId,
                'role_id' => $role->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    private function denyPublish(User $actor, int $storeId): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'smart_ordering.publish')->firstOrFail()->getKey(),
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }

    private function siteId(int $storeId): int
    {
        return (int) DB::table('smart_ordering_sites')->where('store_id', $storeId)->value('id');
    }

    private function draft(int $storeId): SmartOrderingRevision
    {
        return SmartOrderingRevision::query()
            ->where('site_id', $this->siteId($storeId))
            ->where('status', 'draft')
            ->firstOrFail();
    }

    /** @param list<int> $storeIds
     * @return list<array{int,string,string,string|null}>
     */
    private function revisionState(array $storeIds): array
    {
        $siteIds = array_map(fn (int $storeId): int => $this->siteId($storeId), $storeIds);

        return DB::table('smart_ordering_revisions')
            ->whereIn('site_id', $siteIds)
            ->orderBy('id')
            ->get(['id', 'status', 'checksum', 'scheduled_at'])
            ->map(static fn ($row): array => [
                (int) $row->id,
                (string) $row->status,
                (string) $row->checksum,
                $row->scheduled_at,
            ])
            ->all();
    }
}
