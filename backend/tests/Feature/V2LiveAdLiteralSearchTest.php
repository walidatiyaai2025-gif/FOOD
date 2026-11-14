<?php

namespace Tests\Feature;

use App\Models\LiveAd;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2LiveAdLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_search_matches_literal_symbols_in_name_and_both_localized_titles(): void
    {
        $this->actingAs($this->actor('SUPER_ADMIN', 'live-search-admin@example.test'));

        $name = $this->liveAd('b2b', null, 'Name %_!', [
            'title_ar' => 'عنوان اعتيادي',
            'title_en' => 'Ordinary name',
        ]);
        $arabic = $this->liveAd('b2b', null, 'arabic-special', [
            'title_ar' => 'عرض %_! بالعربية',
        ]);
        $english = $this->liveAd('b2b', null, 'english-special', [
            'title_en' => 'Deal %_! in English',
        ]);
        $lookalike = $this->liveAd('b2b', null, 'unrelated !');
        $this->liveAd('b2b', null, 'plain advertising');

        $beforeAudit = DB::table('audit_logs')->count();

        $this->assertResults(['q' => '%_!'], [$name->id, $arabic->id, $english->id]);
        $this->assertResults(['q' => '%'], [$name->id, $arabic->id, $english->id]);
        $this->assertResults(['q' => '_'], [$name->id, $arabic->id, $english->id]);
        $this->assertResults(['q' => '!'], [$name->id, $arabic->id, $english->id, $lookalike->id]);
        $this->assertResults(['q' => 'عرض'], [$arabic->id]);
        $this->assertResults(['q' => 'Deal'], [$english->id]);
        $this->assertResults(['q' => 'plain advertising'], [
            (int) LiveAd::query()->where('name', 'plain advertising')->value('id'),
        ]);

        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('live_ads', [
            'id' => $name->id,
            'name' => 'Name %_!',
            'is_active' => true,
        ]);
    }

    public function test_literal_search_preserves_retail_store_channel_and_status_authority(): void
    {
        $storeA = $this->store('LIVE-LITERAL-A');
        $storeB = $this->store('LIVE-LITERAL-B');

        $mine = $this->liveAd('b2c', $storeA, 'my %_! active');
        $inactive = $this->liveAd('b2c', $storeA, 'my %_! inactive', ['is_active' => false]);
        $foreign = $this->liveAd('b2c', $storeB, 'foreign %_!');
        $wholesale = $this->liveAd('b2b', null, 'wholesale %_!');

        $this->actingAs($this->storeAdmin($storeA));
        $this->assertResults(['q' => '%_!', 'channel' => 'b2c', 'status' => 'active'], [$mine->id]);
        $this->assertResults(['q' => '%_!', 'status' => 'inactive'], [$inactive->id]);
        $this->assertResults(['q' => '%_!', 'channel' => 'b2b'], []);

        $this->actingAs($this->actor('SUPER_ADMIN', 'all-live-search@example.test'));
        $this->assertResults(['q' => '%_!', 'channel' => 'b2b'], [$wholesale->id]);
        $this->assertResults(['q' => '%_!', 'channel' => 'b2c', 'status' => 'active'], [$mine->id, $foreign->id]);

        $this->assertDatabaseHas('live_ads', ['id' => $foreign->id, 'store_id' => $storeB]);
        $this->assertDatabaseHas('live_ads', ['id' => $inactive->id, 'is_active' => false]);
    }

    public function test_unprivileged_user_cannot_read_live_ad_search(): void
    {
        $user = User::query()->create([
            'name' => 'Unprivileged actor',
            'email' => 'denied-live-search@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.live-ads.index', ['q' => '%_!']))
            ->assertForbidden();
    }

    /** @param array<string, string> $filters
     *  @param list<int> $expectedIds
     */
    private function assertResults(array $filters, array $expectedIds): void
    {
        $this->get(route('admin.live-ads.index', $filters))
            ->assertOk()
            ->assertViewHas('ads', static function ($page) use ($expectedIds): bool {
                $actual = $page->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                sort($actual);
                sort($expectedIds);

                return $actual === $expectedIds;
            });
    }

    private function actor(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Live Ad '.$roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Live Ad Admin',
            'email' => 'retail-live-literal@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'live_ads_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function liveAd(string $channel, ?int $storeId, string $name, array $overrides = []): LiveAd
    {
        return LiveAd::query()->create([
            'scope_key' => $channel === 'b2c' ? 'b2c:'.$storeId : 'b2b',
            'channel' => $channel,
            'store_id' => $storeId,
            'name' => $name,
            'title_ar' => 'عنوان '.$name,
            'title_en' => 'Title '.$name,
            'body_ar' => 'نص '.$name,
            'body_en' => 'Body '.$name,
            'frequency' => 'once_per_install',
            'is_dismissible' => true,
            'priority' => 100,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
            ...$overrides,
        ]);
    }
}
