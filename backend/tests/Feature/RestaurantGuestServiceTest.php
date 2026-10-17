<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantGuestService;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RestaurantGuestServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $storeA;

    private int $storeB;

    private SmartOrderingBranch $branchA;

    private SmartOrderingBranch $branchB;

    private User $admin;

    private string $tableA;

    private string $tableB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));

        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->storeA = $this->store($type, 'GUEST-A');
        $this->storeB = $this->store($type, 'GUEST-B');
        $this->enableRestaurantSaasForTestStore($this->storeA);
        $this->enableRestaurantSaasForTestStore($this->storeB);
        $this->branchA = $this->branch($this->storeA, 'guest-a');
        $this->branchB = $this->branch($this->storeB, 'guest-b');

        DB::table('smart_ordering_entitlements')->insert([
            'site_id' => $this->branchA->site_id,
            'branch_id' => null,
            'scope_key' => 'site',
            'module' => 'reservations',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->admin = User::query()->create([
            'name' => 'Restaurant Operator',
            'email' => 'restaurant-guest@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $this->admin->id,
            'store_id' => $this->storeA,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->tableA = $this->table($this->branchA, 'A01', 4);
        $this->tableB = $this->table($this->branchB, 'B01', 4);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reservation_and_waitlist_tabs_extend_the_existing_restaurant_workspace(): void
    {
        app()->setLocale('en');
        $this->actingAs($this->admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'reservations',
                'branch' => $this->branchA->public_id,
            ]))
            ->assertOk()
            ->assertSee('Reservations')
            ->assertSee('Waitlist')
            ->assertSee('Add reservation')
            ->assertSee('/assets/admin/smart-ordering-guest-service.css', false);

        $this->admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($this->admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'waitlist',
                'branch' => $this->branchA->public_id,
            ]))
            ->assertOk()
            ->assertSee('قائمة الانتظار')
            ->assertSee('إضافة إلى قائمة الانتظار');
    }

    public function test_reservations_link_existing_customer_reject_cross_branch_tables_and_conflicts(): void
    {
        $legacyCustomer = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Known Guest',
            'phone' => '+96550000001',
            'email' => 'known@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2c_customers')->insert([
            'legacy_customer_id' => $legacyCustomer,
            'store_id' => $this->storeA,
            'user_id' => null,
            'name' => 'Known Guest',
            'phone' => '+96550000001',
            'email' => 'known@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'guest_name' => 'Known Guest',
            'guest_phone' => '+96550000001',
            'guest_email' => 'known@example.test',
            'party_size' => 4,
            'service_at' => '2026-10-17T19:00',
            'table_public_id' => $this->tableA,
            'notes' => 'Window seat',
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.store', $this->branchA->public_id), $payload)
            ->assertRedirect();

        $reservation = DB::table('smart_ordering_reservations')->first();
        $this->assertNotNull($reservation);
        $this->assertSame($legacyCustomer, (int) $reservation->customer_id);
        $this->assertSame('BOOKED', $reservation->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.reservation.created',
            'store_id' => $this->storeA,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.store', $this->branchA->public_id), [
                ...$payload,
                'guest_name' => 'Conflict Guest',
                'guest_phone' => '+96550000002',
                'guest_email' => null,
                'service_at' => '2026-10-17T20:00',
            ])
            ->assertSessionHasErrors('table_public_id');

        $this->assertSame(1, DB::table('smart_ordering_reservations')->count());

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.store', $this->branchA->public_id), [
                ...$payload,
                'guest_name' => 'Foreign Table Guest',
                'guest_phone' => '+96550000003',
                'guest_email' => null,
                'table_public_id' => $this->tableB,
                'service_at' => '2026-10-18T19:00',
            ])
            ->assertNotFound();
    }

    public function test_invalid_reservation_transition_is_deterministic_and_waitlist_conversion_is_idempotent(): void
    {
        $service = app(RestaurantGuestService::class);
        $reservationPublicId = $service->createReservation($this->branchA, [
            'guest_name' => 'Lifecycle Guest',
            'guest_phone' => '+96550000004',
            'guest_email' => null,
            'party_size' => 2,
            'service_at' => '2026-10-18T19:00',
            'table_public_id' => $this->tableA,
            'notes' => null,
        ], $this->admin);

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.transition', [
                $this->branchA->public_id,
                $reservationPublicId,
            ]), ['status' => 'COMPLETED'])
            ->assertSessionHasErrors('status');

        $waitlistPublicId = $service->createWaitlist($this->branchA, [
            'guest_name' => 'Walk In',
            'guest_phone' => '+96550000005',
            'guest_email' => null,
            'party_size' => 2,
            'quoted_wait_minutes' => 15,
            'priority' => 1,
            'priority_reason' => 'Accessibility',
            'notes' => null,
        ], $this->admin);

        $first = $service->convertWaitlist(
            $this->branchA,
            $waitlistPublicId,
            null,
            false,
            $this->admin,
        );
        $second = $service->convertWaitlist(
            $this->branchA,
            $waitlistPublicId,
            null,
            false,
            $this->admin,
        );

        $this->assertSame($first, $second);
        $this->assertSame(2, DB::table('smart_ordering_reservations')->count());
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('event', 'smart_ordering.waitlist.converted')->count(),
        );
        $this->assertDatabaseHas('smart_ordering_waitlist_entries', [
            'public_id' => $waitlistPublicId,
            'status' => 'CONVERTED',
        ]);
    }

    public function test_cross_tenant_exact_record_actions_fail_closed(): void
    {
        $service = app(RestaurantGuestService::class);
        $reservationPublicId = $service->createReservation($this->branchA, [
            'guest_name' => 'Tenant A',
            'guest_phone' => null,
            'guest_email' => null,
            'party_size' => 2,
            'service_at' => '2026-10-18T19:00',
            'table_public_id' => null,
            'notes' => null,
        ], $this->admin);

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.transition', [
                $this->branchB->public_id,
                $reservationPublicId,
            ]), ['status' => 'CONFIRMED'])
            ->assertNotFound();

        $this->assertDatabaseHas('smart_ordering_reservations', [
            'public_id' => $reservationPublicId,
            'status' => 'BOOKED',
        ]);
    }

    public function test_disabled_entitlement_preserves_existing_data_and_blocks_mutation(): void
    {
        $service = app(RestaurantGuestService::class);
        $reservation = $service->createReservation($this->branchA, [
            'guest_name' => 'Preserved Guest',
            'guest_phone' => '+96550000010',
            'guest_email' => null,
            'party_size' => 2,
            'service_at' => '2026-10-18T19:00',
            'table_public_id' => null,
            'notes' => null,
        ], $this->admin);

        DB::table('smart_ordering_entitlements')
            ->where('site_id', $this->branchA->site_id)
            ->where('scope_key', 'site')
            ->where('module', 'reservations')
            ->update(['enabled' => false, 'updated_at' => now()]);

        $this->actingAs($this->admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'reservations',
                'branch' => $this->branchA->public_id,
            ]))
            ->assertOk()
            ->assertSee('Reservations and waitlist are disabled for this branch');

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.store', $this->branchA->public_id), [
                'guest_name' => 'Blocked Guest',
                'guest_phone' => null,
                'guest_email' => null,
                'party_size' => 2,
                'service_at' => '2026-10-19T19:00',
                'table_public_id' => null,
                'notes' => null,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('smart_ordering_reservations', [
            'public_id' => $reservation,
            'guest_name' => 'Preserved Guest',
        ]);
        $this->assertSame(1, DB::table('smart_ordering_reservations')->count());
    }

    public function test_guest_book_is_derived_from_reservation_and_waitlist_truth_in_both_locales(): void
    {
        $service = app(RestaurantGuestService::class);
        $service->createReservation($this->branchA, [
            'guest_name' => 'Guest Book Person',
            'guest_phone' => '+96550000011',
            'guest_email' => 'guestbook@example.test',
            'party_size' => 2,
            'service_at' => '2026-10-18T19:00',
            'table_public_id' => null,
            'notes' => null,
        ], $this->admin);
        $service->createWaitlist($this->branchA, [
            'guest_name' => 'Guest Book Person',
            'guest_phone' => '+96550000011',
            'guest_email' => 'guestbook@example.test',
            'party_size' => 2,
            'quoted_wait_minutes' => 10,
            'priority' => 0,
            'priority_reason' => null,
            'notes' => null,
        ], $this->admin);

        app()->setLocale('en');
        $this->actingAs($this->admin)
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'guest_book',
                'branch' => $this->branchA->public_id,
            ]))
            ->assertOk()
            ->assertSee('Guest Book')
            ->assertSee('Guest Book Person')
            ->assertSee('guestbook@example.test');

        $this->admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');
        $this->actingAs($this->admin->fresh())
            ->get(route('admin.smart-ordering.operations.index', [
                'tab' => 'guest_book',
                'branch' => $this->branchA->public_id,
            ]))
            ->assertOk()
            ->assertSee('سجل الضيوف')
            ->assertSee('Guest Book Person');
    }

    public function test_seating_rejects_table_occupied_by_canonical_active_order(): void
    {
        $service = app(RestaurantGuestService::class);
        $reservation = $service->createReservation($this->branchA, [
            'guest_name' => 'Seating Guest',
            'guest_phone' => '+96550000012',
            'guest_email' => null,
            'party_size' => 2,
            'service_at' => '2026-10-18T19:00',
            'table_public_id' => $this->tableA,
            'notes' => null,
        ], $this->admin);

        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Active Diner',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $this->storeA,
            'customer_id' => $customerId,
            'order_number' => 'RS-GUEST-ACTIVE-1',
            'channel' => 'smart_ordering',
            'status' => 'pending',
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tableId = (int) DB::table('smart_ordering_tables')
            ->where('public_id', $this->tableA)
            ->value('id');
        DB::table('smart_ordering_order_contexts')->insert([
            'order_id' => $orderId,
            'public_reference' => (string) Str::uuid(),
            'branch_id' => $this->branchA->id,
            'fulfillment_mode' => 'dine_in',
            'table_id' => $tableId,
            'lifecycle_status' => 'CONFIRMED',
            'idempotency_key_hash' => hash('sha256', 'rs-guest-active-order'),
            'request_hash' => hash('sha256', 'rs-guest-active-order-request'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.smart-ordering.reservations.transition', [
                $this->branchA->public_id,
                $reservation,
            ]), ['status' => 'SEATED'])
            ->assertSessionHasErrors('table_public_id');

        $this->assertDatabaseHas('smart_ordering_reservations', [
            'public_id' => $reservation,
            'status' => 'BOOKED',
        ]);
    }

    private function store(int $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function branch(int $storeId, string $slug): SmartOrderingBranch
    {
        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => $slug.'-'.Str::lower(Str::random(6)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => $slug,
            'name_ar' => 'فرع '.$slug,
            'name_en' => 'Branch '.$slug,
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 30,
            'service_charge' => 0,
        ]);
    }

    private function table(SmartOrderingBranch $branch, string $label, int $capacity): string
    {
        $publicId = (string) Str::uuid();
        DB::table('smart_ordering_tables')->insert([
            'public_id' => $publicId,
            'branch_id' => $branch->id,
            'label' => $label,
            'capacity' => $capacity,
            'token_hash' => hash('sha256', $publicId),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }
}
