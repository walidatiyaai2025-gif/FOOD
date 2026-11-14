<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\PlatformCustomer;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2CustomerInvoiceProvenanceGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $customerUser;

    private int $legacyId;

    private int $retailCustomerId;

    private int $platformId;

    private int $otherLegacyId;

    private int $otherRetailCustomerId;

    private int $otherPlatformId;

    private int $storeId;

    private int $otherStoreId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeId = $this->store($retailTypeId, 'V2-3503-STORE-A');
        $this->otherStoreId = $this->store($retailTypeId, 'V2-3503-STORE-B');

        $this->customerUser = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        [$this->legacyId, $this->retailCustomerId, $this->platformId] = $this->customer(
            $this->customerUser,
            $this->storeId,
        );

        $otherUser = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        [$this->otherLegacyId, $this->otherRetailCustomerId, $this->otherPlatformId] = $this->customer(
            $otherUser,
            $this->otherStoreId,
        );

        Sanctum::actingAs($this->customerUser);
    }

    public function test_owned_retail_and_legacy_null_provenance_invoices_are_readable(): void
    {
        $owned = $this->invoice();
        $legacyOnly = $this->invoice([
            'platform_customer_id' => null,
            'b2c_customer_id' => null,
            'channel' => null,
            'store_id' => null,
        ]);

        $response = $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertEqualsCanonicalizing(
            [$owned, $legacyOnly],
            collect($response->json('data'))->pluck('id')->all(),
        );

        $this->getJson('/api/v1/invoices/'.$owned)
            ->assertOk()->assertJsonPath('data.id', $owned);
        $this->getJson('/api/v1/invoices/'.$legacyOnly)
            ->assertOk()->assertJsonPath('data.id', $legacyOnly);
    }

    public function test_authorized_wholesale_invoice_remains_visible_without_a_retail_store_link(): void
    {
        $wholesale = B2bCustomer::query()->create([
            'legacy_customer_id' => $this->legacyId,
            'user_id' => $this->customerUser->getKey(),
            'name' => $this->customerUser->name,
            'email' => $this->customerUser->email,
        ]);
        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $wholesaleStoreId = $this->store($b2bTypeId, 'V2-3503-WHOLESALE');
        $invoice = $this->invoice([
            'store_id' => $wholesaleStoreId,
            'channel' => 'b2b',
            'b2b_customer_id' => $wholesale->getKey(),
            'b2c_customer_id' => null,
        ]);

        $this->getJson('/api/v1/invoices?channel=b2b')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $invoice);
        $this->getJson('/api/v1/invoices/'.$invoice)
            ->assertOk()
            ->assertJsonPath('data.id', $invoice);
    }

    public function test_matching_legacy_or_platform_id_cannot_override_conflicting_foreign_customer_id(): void
    {
        $conflictingPlatform = $this->invoice(['platform_customer_id' => $this->otherPlatformId]);
        $conflictingRetail = $this->invoice(['b2c_customer_id' => $this->otherRetailCustomerId]);
        $conflictingLegacy = $this->invoice(['customer_id' => $this->otherLegacyId]);

        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('meta.total', 0);

        foreach ([$conflictingPlatform, $conflictingRetail, $conflictingLegacy] as $id) {
            $this->getJson('/api/v1/invoices/'.$id)->assertNotFound();
            $this->get('/api/v1/invoices/'.$id.'/download?locale=ar')->assertNotFound();
            $this->get('/api/v1/invoices/'.$id.'/download?locale=en')->assertNotFound();
        }

        $this->assertDatabaseCount('invoices', 3);
    }

    public function test_retail_store_and_channel_mismatch_are_denied_without_deleting_invoices(): void
    {
        $wrongStore = $this->invoice(['store_id' => $this->otherStoreId]);
        $wrongChannel = $this->invoice(['channel' => 'b2b']);
        $valid = $this->invoice();

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $valid);
        $this->getJson('/api/v1/invoices?channel=b2c')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        foreach ([$wrongStore, $wrongChannel] as $id) {
            $this->getJson('/api/v1/invoices/'.$id)->assertNotFound();
            $this->get('/api/v1/invoices/'.$id.'/download')->assertNotFound();
        }

        $this->assertDatabaseCount('invoices', 3);
    }

    private function store(int $storeTypeId, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{int, int, int} */
    private function customer(User $user, int $storeId): array
    {
        $legacy = Customer::query()->create([
            'user_id' => $user->getKey(),
            'type' => 'b2c',
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $retail = B2cCustomer::query()->create([
            'user_id' => $user->getKey(),
            'legacy_customer_id' => $legacy->getKey(),
            'store_id' => $storeId,
            'name' => $user->name,
            'email' => $user->email,
        ]);
        $platform = PlatformCustomer::query()->create([
            'user_id' => $user->getKey(),
            'legacy_customer_id' => $legacy->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => true,
        ]);

        return [(int) $legacy->getKey(), (int) $retail->getKey(), (int) $platform->getKey()];
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(array $overrides = []): int
    {
        $this->sequence++;

        return (int) DB::table('invoices')->insertGetId(array_replace([
            'customer_id' => $this->legacyId,
            'platform_customer_id' => $this->platformId,
            'b2c_customer_id' => $this->retailCustomerId,
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'invoice_number' => 'V2-3503-'.str_pad((string) $this->sequence, 3, '0', STR_PAD_LEFT),
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 12.500,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
