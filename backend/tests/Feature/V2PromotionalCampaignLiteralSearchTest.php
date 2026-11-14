<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2PromotionalCampaignLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_scoped_bilingual_campaign_search_matches_literal_like_metacharacters_only(): void
    {
        $admin = $this->user('campaign-literal-admin@example.test');
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        foreach (['name', 'title_ar', 'title_en'] as $index => $column) {
            $match = $this->campaign("CAMPAIGN-HIT-{$index}");
            $match->{$column} = "SCAN-{$index}%_!end";
            $match->save();

            $falsePositive = $this->campaign("CAMPAIGN-DECOY-{$index}");
            $falsePositive->{$column} = "SCAN-{$index}AB!end";
            $falsePositive->save();
        }

        $this->campaign('NORMAL-CAMPAIGN-SEARCH');
        $this->campaign('STATUS-DRAFT-RECORD', 'draft');
        $this->campaign('STATUS-ACTIVE-RECORD', 'active');
        $before = DB::table('notification_campaigns')->orderBy('id')->get()->toArray();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            foreach (range(0, 2) as $index) {
                $this->actingAs($admin)->get(route('admin.notification-campaigns.index', [
                    'q' => "SCAN-{$index}%_!end",
                ]))->assertOk()
                    ->assertSee("CAMPAIGN-HIT-{$index}")
                    ->assertDontSee("CAMPAIGN-DECOY-{$index}");
            }

            $this->actingAs($admin)->get(route('admin.notification-campaigns.index', [
                'q' => 'NORMAL-CAMPAIGN',
            ]))->assertOk()->assertSee('NORMAL-CAMPAIGN-SEARCH')
                ->assertDontSee('STATUS-DRAFT-RECORD');

            $this->actingAs($admin)->get(route('admin.notification-campaigns.index', [
                'q' => 'STATUS-ACTIVE',
                'status' => 'draft',
            ]))->assertOk()->assertDontSee('STATUS-ACTIVE-RECORD English');

            $this->actingAs($admin)->get(route('admin.notification-campaigns.index', [
                'q' => 'STATUS-ACTIVE',
                'status' => 'active',
            ]))->assertOk()->assertSee('STATUS-ACTIVE-RECORD English');
        }

        $this->assertEquals($before, DB::table('notification_campaigns')->orderBy('id')->get()->toArray());
    }

    public function test_retail_store_scope_and_unauthorized_http_access_remain_enforced(): void
    {
        $owned = $this->store('CAMPAIGN-LITERAL-OWNED');
        $foreign = $this->store('CAMPAIGN-LITERAL-FOREIGN');
        $manager = $this->user('campaign-literal-store@example.test');
        // Store roles are assigned through the canonical user_store_roles pivot.
        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $owned,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->campaign('OWNED-CAMPAIGN-LITERAL', 'draft', $owned);
        $this->campaign('FOREIGN-CAMPAIGN-LITERAL', 'draft', $foreign);

        $this->actingAs($manager)->get(route('admin.notification-campaigns.index', [
            'q' => 'CAMPAIGN-LITERAL',
        ]))->assertOk()->assertSee('OWNED-CAMPAIGN-LITERAL')
            ->assertDontSee('FOREIGN-CAMPAIGN-LITERAL');

        $unprivileged = $this->user('campaign-literal-denied@example.test');
        $this->actingAs($unprivileged)->get(route('admin.notification-campaigns.index', [
            'q' => '%_!',
        ]))->assertForbidden();
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function campaign(string $tag, string $status = 'draft', ?int $storeId = null): NotificationCampaign
    {
        return NotificationCampaign::query()->create([
            'name' => $tag,
            'type' => 'promotion',
            'title_ar' => $tag.' عربي',
            'title_en' => $tag.' English',
            'body_ar' => 'عرض متاح الآن',
            'body_en' => 'Offer available now',
            'audience' => 'all',
            'app' => 'customer',
            'target_channel' => $storeId === null ? 'all' : 'b2c',
            'store_id' => $storeId,
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => $status,
        ]);
    }
}
