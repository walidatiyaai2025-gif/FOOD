<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuReadService;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SmartOrderingScheduledPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-16 13:00:00', 'Asia/Kuwait'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_operator_schedules_frozen_menu_revision_and_next_edit_uses_new_draft(): void
    {
        $fixture = $this->fixture('SCHEDULE-A');
        $draft = $this->menuDraft($fixture, 'Scheduled Menu');
        $publishedBefore = app(SmartOrderingRevisionService::class)->published($fixture['site']);
        $this->assertNotNull($publishedBefore);

        $scheduledAt = now()->addHour();

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'review' => 1,
            ]))
            ->assertOk()
            ->assertSee('type="datetime-local"', false)
            ->assertSee(__('smart_ordering_menu.schedule.action'))
            ->assertSee(__('smart_ordering_menu.schedule.timezone', ['timezone' => 'UTC']));

        $this->actingAs($fixture['admin'])
            ->post(route('admin.smart-ordering.menu.schedule', $draft), [
                'store_id' => $fixture['store_id'],
                'scheduled_at' => $scheduledAt->format('Y-m-d\\TH:i'),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('smart_ordering_menu.scheduled'));

        $scheduled = $draft->fresh();
        $this->assertSame('scheduled', $scheduled->status);
        $this->assertSame($fixture['admin']->id, $scheduled->scheduled_by);
        $this->assertTrue($scheduled->scheduled_at?->equalTo($scheduledAt));
        $this->assertSame($publishedBefore->id, app(SmartOrderingRevisionService::class)->published($fixture['site'])?->id);

        try {
            app(SmartOrderingRevisionService::class)->updateDraft(
                $fixture['admin'],
                $scheduled,
                (array) $scheduled->payload,
            );
            $this->fail('A scheduled revision must be immutable.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $nextDraft = app(SmartOrderingMenuRevisionService::class)->draft(
            $fixture['admin'],
            $fixture['site']->fresh(),
        );
        $this->assertNotSame($scheduled->id, $nextDraft->id);
        $this->assertSame($scheduled->id, $nextDraft->parent_revision_id);
        $this->assertSame('draft', $nextDraft->status);
        $this->assertNull($nextDraft->scheduled_at);
        $this->assertNull($nextDraft->scheduled_by);
        $this->assertSame(
            'Scheduled Menu',
            DB::table('smart_ordering_menus')
                ->where('revision_id', $nextDraft->id)
                ->value('name_en'),
        );

        try {
            app(SmartOrderingMenuRevisionService::class)->publish(
                $fixture['admin'],
                $fixture['site']->fresh(),
                $nextDraft->fresh(),
            );
            $this->fail('Publish-now must not bypass a pending scheduled revision.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->actingAs($fixture['admin'])
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $fixture['store_id'],
                'review' => 1,
            ]))
            ->assertOk()
            ->assertSee('id="so-scheduled-publish-state"', false)
            ->assertSee(__('smart_ordering_menu.schedule.pending_hint'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.revision.scheduled',
            'store_id' => $fixture['store_id'],
            'user_id' => $fixture['admin']->id,
        ]);
    }

    public function test_dispatcher_publishes_only_due_revision_and_keeps_future_revision_scheduled(): void
    {
        $due = $this->fixture('SCHEDULE-DUE');
        $future = $this->fixture('SCHEDULE-FUTURE');

        $dueDraft = $this->menuDraft($due, 'Due Menu');
        $futureDraft = $this->menuDraft($future, 'Future Menu');
        $duePublishedBefore = app(SmartOrderingRevisionService::class)->published($due['site']);
        $futurePublishedBefore = app(SmartOrderingRevisionService::class)->published($future['site']);
        $this->assertNotNull($duePublishedBefore);
        $this->assertNotNull($futurePublishedBefore);

        $menuRevisions = app(SmartOrderingMenuRevisionService::class);
        $menuRevisions->schedule($due['admin'], $due['site'], $dueDraft, now()->addMinutes(10));
        $menuRevisions->schedule($future['admin'], $future['site'], $futureDraft, now()->addHour());

        Carbon::setTestNow(now()->addMinutes(20));

        $exit = Artisan::call('foodex:publish-scheduled-smart-ordering', ['--limit' => 25]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'Published 1 scheduled Smart Ordering revision(s).',
            Artisan::output(),
        );

        $this->assertSame('published', $dueDraft->fresh()->status);
        $this->assertSame($due['admin']->id, $dueDraft->fresh()->published_by);
        $this->assertNotNull($dueDraft->fresh()->published_at);
        $this->assertSame('archived', $duePublishedBefore->fresh()->status);

        $this->assertSame('scheduled', $futureDraft->fresh()->status);
        $this->assertSame('published', $futurePublishedBefore->fresh()->status);

        $menu = app(SmartOrderingMenuReadService::class)->menu($due['site']->fresh(), 'en');
        $this->assertSame('Due Menu', $menu['menus'][0]['name']);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.revision.published',
            'store_id' => $due['store_id'],
            'user_id' => $due['admin']->id,
        ]);
    }

    public function test_scheduling_rejects_past_time_and_cross_tenant_actor(): void
    {
        $owner = $this->fixture('SCHEDULE-OWNER');
        $foreign = $this->fixture('SCHEDULE-FOREIGN');
        $draft = $this->menuDraft($owner, 'Protected Menu');
        $service = app(SmartOrderingMenuRevisionService::class);

        try {
            $service->schedule($owner['admin'], $owner['site'], $draft, now()->subMinute());
            $this->fail('A past scheduled publish time must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_at', $exception->errors());
        }

        try {
            $service->schedule($foreign['admin'], $owner['site'], $draft->fresh(), now()->addHour());
            $this->fail('A foreign restaurant actor must not schedule this revision.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($foreign['admin'])
            ->post(route('admin.smart-ordering.menu.schedule', $draft), [
                'store_id' => $foreign['store_id'],
                'scheduled_at' => now()->addHour()->format('Y-m-d\\TH:i'),
            ])
            ->assertNotFound();

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_only_one_pending_scheduled_revision_is_allowed_per_site(): void
    {
        $fixture = $this->fixture('SCHEDULE-ONE');
        $firstDraft = $this->menuDraft($fixture, 'First Scheduled Menu');
        $service = app(SmartOrderingMenuRevisionService::class);
        $service->schedule($fixture['admin'], $fixture['site'], $firstDraft, now()->addHour());

        $secondDraft = $service->draft($fixture['admin'], $fixture['site']->fresh());

        try {
            $service->schedule($fixture['admin'], $fixture['site']->fresh(), $secondDraft, now()->addHours(2));
            $this->fail('A site must not have multiple pending scheduled revisions.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame('scheduled', $firstDraft->fresh()->status);
        $this->assertSame('draft', $secondDraft->fresh()->status);
    }

    /**
     * @param  array{store_id:int,admin:User,site:SmartOrderingSite}  $fixture
     */
    private function menuDraft(array $fixture, string $menuName): SmartOrderingRevision
    {
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $writer = app(SmartOrderingMenuWriteService::class);
        $draft = $revisions->draft($fixture['admin'], $fixture['site']);

        $menu = $writer->createMenu($fixture['admin'], $fixture['site'], $draft, [
            'name_en' => $menuName,
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $category = $writer->createCategory($fixture['admin'], $fixture['site'], $draft, $menu, [
            'name_en' => 'Meals',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);
        $writer->createItem($fixture['admin'], $fixture['site'], $draft, $category, [
            'name_en' => 'Scheduled Item',
            'price_override' => 4.250,
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
        ]);

        return $draft->fresh();
    }

    /** @return array{store_id:int,admin:User,site:SmartOrderingSite} */
    private function fixture(string $suffix): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $suffix,
            'name' => $suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => 'Restaurant Admin '.$suffix,
            'email' => strtolower($suffix).'-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableRestaurantSaasForTestStore($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [
            'store_id' => $storeId,
            'admin' => $admin,
            'site' => $site,
        ];
    }
}
