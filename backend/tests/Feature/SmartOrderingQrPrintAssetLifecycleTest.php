<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SmartOrderingQrManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SmartOrderingQrPrintAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_default_svg_and_pdf_assets_are_bound_to_the_active_token_version(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $qr = $created['qr']->fresh();
        $rawToken = $created['token'];
        $version = hash('sha256', $rawToken);
        $admin = $this->storeAdmin($store);

        $context = $manager->printAssetContext($qr);
        $this->assertSame($manager->publicUrlFromRaw($rawToken), $context['url']);
        $this->assertSame($version, $context['version']);
        $this->assertSame('active', $context['state']);

        $svg = $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$qr->public_id.'/asset.svg');

        $svg->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-FOODEX-QR-Asset-Version', $version)
            ->assertHeader('X-FOODEX-QR-Asset-State', 'active');
        $this->assertStringContainsString('version='.$version, (string) $svg->headers->get('Content-Location'));
        $this->assertStringNotContainsString($rawToken, json_encode($svg->headers->all(), JSON_THROW_ON_ERROR));

        $pdf = $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$qr->public_id.'/asset.pdf');

        $pdf->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-FOODEX-QR-Asset-Version', $version)
            ->assertHeader('X-FOODEX-QR-Asset-State', 'active');
        $this->assertStringContainsString('version='.$version, (string) $pdf->headers->get('Content-Location'));
        $this->assertStringNotContainsString($rawToken, json_encode($pdf->headers->all(), JSON_THROW_ON_ERROR));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_rotation_makes_new_active_asset_default_and_retains_explicit_legacy_asset(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $qr = $created['qr']->fresh();
        $oldRawToken = $created['token'];
        $oldVersion = hash('sha256', $oldRawToken);

        $newRawToken = $manager->rotate($qr);
        $newVersion = hash('sha256', $newRawToken);

        $default = $manager->printAssetContext($qr->fresh());
        $legacy = $manager->printAssetContext($qr->fresh(), $oldVersion);

        $this->assertSame($newVersion, $default['version']);
        $this->assertSame('active', $default['state']);
        $this->assertSame($manager->publicUrlFromRaw($newRawToken), $default['url']);
        $this->assertSame($oldVersion, $legacy['version']);
        $this->assertSame('legacy', $legacy['state']);
        $this->assertSame($manager->publicUrlFromRaw($oldRawToken), $legacy['url']);

        $admin = $this->storeAdmin($store);
        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$qr->public_id.'/asset.svg?version='.$oldVersion)
            ->assertOk()
            ->assertHeader('X-FOODEX-QR-Asset-Version', $oldVersion)
            ->assertHeader('X-FOODEX-QR-Asset-State', 'legacy');

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$qr->public_id.'/asset.svg')
            ->assertOk()
            ->assertHeader('X-FOODEX-QR-Asset-Version', $newVersion)
            ->assertHeader('X-FOODEX-QR-Asset-State', 'active');
    }

    public function test_revoked_expired_unknown_and_foreign_versions_fail_closed(): void
    {
        [$storeA, $storeB] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);

        $revoked = $manager->create($this->payload($storeA, ['label' => 'Revoked print']));
        $revokedQr = $revoked['qr']->fresh();
        $revokedVersion = hash('sha256', $revoked['token']);
        $manager->rotate($revokedQr, null, false);

        $legacy = $manager->create($this->payload($storeA, ['label' => 'Expired legacy']));
        $legacyQr = $legacy['qr']->fresh();
        $legacyVersion = hash('sha256', $legacy['token']);
        $manager->rotate($legacyQr);
        DB::table('smart_ordering_qr_tokens')
            ->where('qr_code_id', $legacyQr->id)
            ->where('token_hash', $legacyVersion)
            ->update(['legacy_expires_at' => now()->subMinute()]);

        $foreign = $manager->create($this->payload($storeB, ['label' => 'Foreign print']));
        $foreignVersion = hash('sha256', $foreign['token']);

        $admin = $this->storeAdmin($storeA);

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$revokedQr->public_id.'/asset.svg?version='.$revokedVersion)
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$legacyQr->public_id.'/asset.svg?version='.$legacyVersion)
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$legacyQr->public_id.'/asset.svg?version='.str_repeat('a', 64))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$legacyQr->public_id.'/asset.svg?version='.$foreignVersion)
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$foreign['qr']->public_id.'/asset.svg')
            ->assertNotFound();
    }

    public function test_malformed_version_is_rejected_without_falling_back_to_active_asset(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $admin = $this->storeAdmin($store);

        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr/'.$created['qr']->public_id.'/asset.svg?version=not-a-version')
            ->assertNotFound();
    }

    /** @return array{0:int,1:int} */
    private function stores(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $a = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'QR-PRINT-A',
            'name' => 'QR Print Restaurant A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $b = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => 'QR-PRINT-B',
            'name' => 'QR Print Restaurant B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableRestaurantSaasForTestStore($a);
        $this->enableRestaurantSaasForTestStore($b);

        return [$a, $b];
    }

    /** @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $storeId, array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $storeId,
            'type' => 'generic',
            'label' => 'Main QR print',
            'target_path' => '/menu',
            'source' => 'qr',
            'medium' => 'offline',
            'campaign' => null,
            'branch_reference' => null,
            'table_reference' => null,
            'promotion_id' => null,
            'valid_from' => null,
            'valid_until' => null,
            'is_enabled' => true,
        ];
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'QR Print Admin',
            'email' => 'qr-print-'.$storeId.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
