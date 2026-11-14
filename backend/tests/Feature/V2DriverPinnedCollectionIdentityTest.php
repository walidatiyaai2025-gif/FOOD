<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDriverRuntimeAccess;
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

    public function test_retail_driver_wallet_is_scoped_to_its_authorized_profile_not_other_users_wholesale_custody(): void
    {
        [$retailUser, $retailDriver, $retailAccount, , $foreignAccount] = $this->fixtures();
        Sanctum::actingAs($retailUser, ['app:driver']);

        $this->getJson('/api/v1/driver/wallet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retailAccount->id)
            ->assertJsonPath('data.0.currency', 'EGP');

        $this->assertNotEquals($foreignAccount->actor_id, $retailDriver->id);
        $this->assertDatabaseCount('remittances', 0);
    }

    public function test_foreign_wholesale_account_remittance_is_denied_without_writes_but_retail_remittance_is_idempotent(): void
    {
        [$retailUser, , $retailAccount, , $foreignAccount] = $this->fixtures();
        Sanctum::actingAs($retailUser, ['app:driver']);

        $this->postJson('/api/v1/driver/wallet/remittances', [
            'collection_account_id' => $foreignAccount->id,
            'amount' => 4,
            'method' => 'cash',
        ], ['Idempotency-Key' => 'pinned-foreign-remit-123'])->assertNotFound();
        $this->assertDatabaseCount('remittances', 0);

        app(CollectionCustodyService::class)->collect(
            $retailAccount,
            $retailUser,
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

        $this->assertSame($first->json('data.remittance.id'), $second->json('data.remittance.id'));
        $this->assertDatabaseCount('remittances', 1);
        $this->assertDatabaseHas('remittances', [
            'collection_account_id' => $retailAccount->id,
            'amount' => 4,
            'status' => 'pending',
        ]);
    }

    public function test_wholesale_only_driver_and_wrong_app_token_are_denied_before_finance_access(): void
    {
        [$retailUser, , , $wholesaleUser] = $this->fixtures();

        Sanctum::actingAs($wholesaleUser, ['app:driver']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->postJson('/api/v1/driver/wallet/remittances', [
            'collection_account_id' => 999,
            'amount' => 1,
            'method' => 'cash',
        ], ['Idempotency-Key' => 'b2b-only-denied-123'])->assertForbidden();

        Sanctum::actingAs($retailUser, ['app:customer']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->assertDatabaseCount('remittances', 0);
    }

    public function test_wallet_rejects_missing_middleware_pinned_driver_context(): void
    {
        [$retailUser] = $this->fixtures();
        Sanctum::actingAs($retailUser, ['app:driver']);

        // Simulate future route/middleware wiring regression. A scoped token
        // alone is not enough to access custody without the verified identity.
        $this->withoutMiddleware(EnsureDriverRuntimeAccess::class);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
    }

    /** @return array{0: User, 1: Driver, 2: CollectionAccount, 3: User, 4: CollectionAccount} */
    private function fixtures(): array
    {
        $this->seed(CoreReferenceSeeder::class);

        $retailUser = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $retailUser->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-PINNED-DRIVER-RETAIL',
            'name' => 'V2 Pinned Driver Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailDriver = Driver::query()->create([
            'user_id' => $retailUser->id,
            'store_id' => $retailStore,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $retailAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $retailDriver->id,
            'store_id' => $retailStore,
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        // Independent historical Wholesale driver: drivers.user_id is UNIQUE,
        // so mixed B2B/B2C profiles for the same user are NOT a valid fixture.
        $wholesaleUser = User::factory()->create(['is_active' => true]);
        $wholesaleUser->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());
        $wholesaleDriver = Driver::query()->create([
            'user_id' => $wholesaleUser->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);
        $foreignAccount = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $wholesaleDriver->id,
            'store_id' => (int) $wholesaleDriver->store_id,
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        return [$retailUser, $retailDriver, $retailAccount, $wholesaleUser, $foreignAccount];
    }
}
