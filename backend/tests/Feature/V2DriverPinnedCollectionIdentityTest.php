<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2DriverPinnedCollectionIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_history_wallet_reads_only_the_middleware_authorized_retail_profile(): void
    {
        [$user, , $retailDriver, $legacyAccount, $retailAccount] = $this->mixedProfiles();
        Sanctum::actingAs($user, ['app:driver']);

        $this->getJson('/api/v1/driver/wallet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retailAccount->id)
            ->assertJsonPath('data.0.currency', 'EGP');

        $this->assertNotEquals($legacyAccount->actor_id, $retailDriver->id);
        $this->assertDatabaseCount('remittances', 0);
    }

    public function test_mixed_history_remittance_cannot_use_wholesale_custody_but_retail_remit_is_idempotent(): void
    {
        [$user, , , $legacyAccount, $retailAccount] = $this->mixedProfiles();
        Sanctum::actingAs($user, ['app:driver']);

        $this->postJson('/api/v1/driver/wallet/remittances', [
            'collection_account_id' => $legacyAccount->id,
            'amount' => 4,
            'method' => 'cash',
        ], ['Idempotency-Key' => 'pinned-legacy-remit-123'])
            ->assertNotFound();
        $this->assertDatabaseCount('remittances', 0);

        app(CollectionCustodyService::class)->collect(
            $retailAccount,
            $user,
            'pinned-retail-existing-collection',
            10,
            'EGP',
            'driver_app',
        );

        $payload = [
            'collection_account_id' => $retailAccount->id,
            'amount' => 4,
            'method' => 'cash',
            'reference' => 'RETAIL-ONLY',
        ];
        $headers = ['Idempotency-Key' => 'pinned-retail-remit-123'];

        $first = $this->postJson('/api/v1/driver/wallet/remittances', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.remittance.status', 'pending')
            ->assertJsonPath('data.remittance.currency', 'EGP');
        $second = $this->postJson('/api/v1/driver/wallet/remittances', $payload, $headers)
            ->assertCreated();

        $this->assertSame(
            $first->json('data.remittance.id'),
            $second->json('data.remittance.id'),
        );
        $this->assertDatabaseCount('remittances', 1);
        $this->assertDatabaseHas('remittances', [
            'collection_account_id' => $retailAccount->id,
            'amount' => 4,
            'status' => 'pending',
        ]);
    }

    public function test_b2b_only_driver_and_wrong_app_token_are_denied_before_finance_access(): void
    {
        [$user, $legacyDriver] = $this->mixedProfiles();
        $legacyDriver->user->roles()->sync([Role::query()->where('code', 'B2C_DRIVER')->firstOrFail()->id]);
        Driver::query()->where('user_id', $user->id)->where('driver_type', 'b2c')->delete();

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->postJson('/api/v1/driver/wallet/remittances', [
            'collection_account_id' => 999,
            'amount' => 1,
            'method' => 'cash',
        ], ['Idempotency-Key' => 'b2b-only-denied-123'])->assertForbidden();

        Sanctum::actingAs($user, ['app:customer']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->assertDatabaseCount('remittances', 0);
    }

    /** @return array{0: User, 1: Driver, 2: Driver, 3: CollectionAccount, 4: CollectionAccount} */
    private function mixedProfiles(): array
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        // Deliberately insert Wholesale before Retail to catch first() regressions.
        $legacyDriver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-PINNED-DRIVER-RETAIL',
            'name' => 'V2 Pinned Driver Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailDriver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $legacyAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $legacyDriver->id,
            'store_id' => (int) $legacyDriver->store_id,
            'currency' => 'EGP',
            'status' => 'active',
        ]);
        $retailAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $retailDriver->id,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        return [$user, $legacyDriver, $retailDriver, $legacyAccount, $retailAccount];
    }
}
