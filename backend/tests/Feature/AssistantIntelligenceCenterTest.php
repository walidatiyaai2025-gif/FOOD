<?php

namespace Tests\Feature;

use App\Domain\Assistant\Intelligence\AssistantBriefDispatcher;
use App\Domain\Assistant\Intelligence\AssistantBriefService;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceAuthorization;
use App\Domain\Assistant\Intelligence\AssistantIntelligenceService;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceBrief;
use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantIntelligenceCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config([
            'assistant.enabled' => true,
            'assistant.read_only' => true,
            'assistant.external_ai.enabled' => false,
        ]);
    }

    public function test_scheduler_is_idempotent_and_provider_independent(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'en');
        $intelligence = app(AssistantIntelligenceService::class);
        $intelligence->updateGlobalSubscription($user, [
            'enabled' => true,
            'daily_enabled' => true,
            'weekly_enabled' => true,
            'minimum_severity' => 'info',
            'locale' => 'en',
        ]);

        $this->finding($user, [
            'dedupe_key' => 'scheduler-sales-drop',
            'severity' => 'warning',
            'title_en' => 'Sales momentum dropped',
            'title_ar' => 'انخفاض زخم المبيعات',
        ]);

        $this->assertSame(0, Artisan::call('foodex:assistant-intelligence-briefs', ['cadence' => 'daily']));
        $this->assertSame(0, Artisan::call('foodex:assistant-intelligence-briefs', ['cadence' => 'daily']));

        $this->assertDatabaseCount('assistant_intelligence_briefs', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'assistant.intelligence_brief',
            'channel' => 'in_app',
            'status' => 'published',
        ]);

        $brief = AssistantIntelligenceBrief::query()->firstOrFail();
        $this->assertSame('deterministic_software', data_get($brief->payload, 'numbers_authority'));
        $this->assertFalse((bool) data_get($brief->payload, 'provider_required'));
        $this->assertNotNull($brief->snapshot_at);
    }

    public function test_delivery_reauthorizes_after_permission_is_revoked(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'en');
        $intelligence = app(AssistantIntelligenceService::class);
        $subscription = $intelligence->updateGlobalSubscription($user, [
            'enabled' => true,
            'daily_enabled' => true,
            'weekly_enabled' => false,
            'minimum_severity' => 'info',
            'locale' => 'en',
        ]);
        $this->finding($user, ['dedupe_key' => 'permission-change']);

        $brief = app(AssistantBriefService::class)->generate($subscription, 'daily');
        $this->assertInstanceOf(AssistantIntelligenceBrief::class, $brief);

        $user->roles()->detach();
        $user->unsetRelation('roles');

        $this->assertFalse(app(AssistantBriefDispatcher::class)->deliver($brief));
        $this->assertSame(
            AssistantIntelligenceBrief::STATE_BLOCKED,
            $brief->fresh()->state,
        );
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'type' => 'assistant.intelligence_brief',
        ]);
    }

    public function test_stale_findings_are_excluded_and_brief_is_localized(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'ar');
        $intelligence = app(AssistantIntelligenceService::class);
        $subscription = $intelligence->updateGlobalSubscription($user, [
            'enabled' => true,
            'daily_enabled' => true,
            'weekly_enabled' => true,
            'minimum_severity' => 'opportunity',
            'locale' => 'ar',
        ]);
        $asOf = CarbonImmutable::now('Asia/Kuwait')->startOfMinute();

        $this->finding($user, [
            'dedupe_key' => 'stale-finding',
            'severity' => 'critical',
            'title_ar' => 'نتيجة قديمة',
            'title_en' => 'Stale finding',
            'snapshot_at' => $asOf->subHours(2),
            'stale_after_at' => $asOf->subMinute(),
        ]);
        $fresh = $this->finding($user, [
            'dedupe_key' => 'fresh-opportunity',
            'severity' => 'opportunity',
            'title_ar' => 'فرصة بيع حالية',
            'title_en' => 'Current sales opportunity',
            'snapshot_at' => $asOf->subMinutes(10),
            'stale_after_at' => $asOf->addHour(),
        ]);

        $brief = app(AssistantBriefService::class)->generate($subscription, 'daily', $asOf);
        $this->assertInstanceOf(AssistantIntelligenceBrief::class, $brief);
        $this->assertSame('ar', $brief->locale);
        $this->assertSame(1, data_get($brief->payload, 'finding_count'));
        $this->assertSame($fresh->id, data_get($brief->payload, 'items.0.finding_id'));
        $this->assertSame('فرصة بيع حالية', data_get($brief->payload, 'items.0.title'));
        $this->assertSame($asOf->toIso8601String(), data_get($brief->payload, 'snapshot_at'));
    }

    public function test_intelligence_center_lifecycle_is_user_scoped_and_audited(): void
    {
        $owner = $this->globalUser('B2B_ADMIN', 'en');
        $other = $this->globalUser('B2B_ADMIN', 'en');
        $finding = $this->finding($owner, ['dedupe_key' => 'lifecycle-finding']);

        $this->actingAs($other)
            ->post(route('admin.assistant-intelligence.findings.acknowledge', $finding->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('admin.assistant-intelligence.index'))
            ->assertOk()
            ->assertSee('Intelligence Center')
            ->assertSee('Evidence');

        $this->actingAs($owner)
            ->post(route('admin.assistant-intelligence.findings.acknowledge', $finding->id))
            ->assertRedirect();

        $this->assertDatabaseHas('assistant_intelligence_findings', [
            'id' => $finding->id,
            'status' => AssistantIntelligenceFinding::STATUS_ACKNOWLEDGED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'assistant.intelligence.finding_acknowledged',
            'user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->post(route('admin.assistant-intelligence.findings.resolve', $finding->id))
            ->assertRedirect();

        $this->assertDatabaseHas('assistant_intelligence_findings', [
            'id' => $finding->id,
            'status' => AssistantIntelligenceFinding::STATUS_RESOLVED,
        ]);
    }

    public function test_subscription_threshold_filters_lower_severity_findings(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'en');
        $intelligence = app(AssistantIntelligenceService::class);
        $subscription = $intelligence->updateGlobalSubscription($user, [
            'enabled' => true,
            'daily_enabled' => true,
            'weekly_enabled' => true,
            'minimum_severity' => 'warning',
            'locale' => 'en',
        ]);

        $this->finding($user, [
            'dedupe_key' => 'below-threshold',
            'severity' => 'opportunity',
            'title_en' => 'Below threshold',
        ]);
        $warning = $this->finding($user, [
            'dedupe_key' => 'at-threshold',
            'severity' => 'warning',
            'title_en' => 'At threshold',
        ]);

        $brief = app(AssistantBriefService::class)->generate($subscription, 'daily');

        $this->assertSame(1, data_get($brief?->payload, 'finding_count'));
        $this->assertSame($warning->id, data_get($brief?->payload, 'items.0.finding_id'));
    }

    public function test_sellout_consent_is_rechecked_after_finding_generation(): void
    {
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $retailerStoreId = $this->retailStore('AI18-SELL-OUT');
        $this->linkRetailWholesaleAccount($retailerStoreId);

        $wholesaleUser = $this->globalUser('B2B_ADMIN', 'en');
        $retailOwner = $this->storeUser($retailerStoreId, 'B2C_STORE_ADMIN', 'en');
        $policy = app(RetailerSellOutAccessPolicy::class);
        $policy->grant(
            $retailOwner,
            $retailerStoreId,
            $wholesaleStoreId,
            RetailerSellOutAccessPolicy::BASIS_OPT_IN,
        );

        $finding = app(AssistantIntelligenceService::class)->upsertForUser($wholesaleUser, [
            'source_key' => 'retailer360.sellout',
            'dedupe_key' => 'sellout-consent-recheck',
            'severity' => 'opportunity',
            'title_ar' => 'فرصة بيع بالتجزئة',
            'title_en' => 'Retail sell-out opportunity',
            'narrative_ar' => 'سرد مرتبط ببيانات بيع مصرح بها.',
            'narrative_en' => 'Narrative tied to consented sell-out evidence.',
            'evidence' => [
                'data_class' => 'SELL_OUT',
                'retailer_store_ids' => [$retailerStoreId],
                'wholesale_store_id' => $wholesaleStoreId,
                'metric' => 'sell_out_units',
                'value' => 12,
            ],
            'snapshot_at' => CarbonImmutable::now('Asia/Kuwait'),
            'stale_after_at' => CarbonImmutable::now('Asia/Kuwait')->addHour(),
            'store_id' => $wholesaleStoreId,
            'channel' => 'b2b',
        ]);

        $this->assertTrue(
            app(AssistantIntelligenceAuthorization::class)
                ->canUseFinding($wholesaleUser, $finding),
        );

        $subscription = app(AssistantIntelligenceService::class)->globalSubscription($wholesaleUser);
        $brief = app(AssistantBriefService::class)->generate($subscription, 'daily');
        $this->assertInstanceOf(AssistantIntelligenceBrief::class, $brief);
        $this->assertTrue(app(AssistantBriefDispatcher::class)->canAccess($wholesaleUser, $brief));

        $policy->revoke($retailOwner, $retailerStoreId, $wholesaleStoreId);

        $this->assertFalse(
            app(AssistantIntelligenceAuthorization::class)
                ->canUseFinding($wholesaleUser->fresh(), $finding->fresh()),
        );
        $this->assertCount(
            0,
            app(AssistantIntelligenceService::class)->visibleFindings($wholesaleUser->fresh()),
        );
        $this->assertFalse(
            app(AssistantBriefDispatcher::class)->canAccess($wholesaleUser->fresh(), $brief->fresh()),
        );

        $response = $this->actingAs($wholesaleUser->fresh())
            ->get(route('admin.assistant-intelligence.index'))
            ->assertOk();

        $this->assertCount(0, $response->viewData('briefHistory')->getCollection());
    }

    public function test_global_assistant_runtime_switch_disables_intelligence_access(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'en');
        config(['assistant.enabled' => false]);

        $this->actingAs($user)
            ->get(route('admin.assistant-intelligence.index'))
            ->assertForbidden();
    }

    private function globalUser(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode.' intelligence',
            'email' => strtolower($roleCode).'-'.uniqid().'@assistant-intelligence.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
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

    private function storeUser(int $storeId, string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode.' intelligence store user',
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@assistant-intelligence.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function linkRetailWholesaleAccount(int $retailStoreId): void
    {
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'Retail link '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStoreId,
            'b2b_customer_id' => $customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function finding(User $user, array $overrides = []): AssistantIntelligenceFinding
    {
        $now = CarbonImmutable::now('Asia/Kuwait');

        return app(AssistantIntelligenceService::class)->upsertForUser($user, [
            'source_key' => $overrides['source_key'] ?? 'test.deterministic',
            'dedupe_key' => $overrides['dedupe_key'] ?? 'finding-'.uniqid(),
            'severity' => $overrides['severity'] ?? 'warning',
            'title_ar' => $overrides['title_ar'] ?? 'نتيجة قابلة للمراجعة',
            'title_en' => $overrides['title_en'] ?? 'Reviewable finding',
            'narrative_ar' => $overrides['narrative_ar'] ?? 'السرد مرتبط بالدليل الحتمي.',
            'narrative_en' => $overrides['narrative_en'] ?? 'Narrative is tied to deterministic evidence.',
            'evidence' => $overrides['evidence'] ?? [
                'metric' => 'net_revenue',
                'value' => 125.5,
                'period' => '24h',
                'source' => 'deterministic_test_fixture',
            ],
            'proposed_action' => $overrides['proposed_action'] ?? [
                'label_ar' => 'راجع الحساب',
                'label_en' => 'Review account',
                'approved' => true,
            ],
            'snapshot_at' => $overrides['snapshot_at'] ?? $now->subMinutes(5),
            'stale_after_at' => array_key_exists('stale_after_at', $overrides)
                ? $overrides['stale_after_at']
                : $now->addHours(2),
            'store_id' => $overrides['store_id'] ?? null,
            'channel' => $overrides['channel'] ?? null,
        ]);
    }
}
