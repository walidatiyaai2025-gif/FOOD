<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Branch-scoped reservation, waitlist and guest-book operations for Restaurant SaaS W3.
 *
 * Guest contact data remains scoped to the restaurant record. Existing FOODEX customer
 * identity is linked when an authoritative B2C match exists; this service never creates
 * a second customer/guest master.
 */
final class RestaurantGuestService
{
    private const RESERVATION_ACTIVE = ['BOOKED', 'CONFIRMED', 'SEATED'];

    private const RESERVATION_GRAPH = [
        'BOOKED' => ['CONFIRMED', 'SEATED', 'CANCELLED', 'NO_SHOW'],
        'CONFIRMED' => ['SEATED', 'CANCELLED', 'NO_SHOW'],
        'SEATED' => ['COMPLETED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
        'NO_SHOW' => [],
    ];

    private const WAITLIST_GRAPH = [
        'WAITING' => ['CONTACTED', 'CANCELLED'],
        'CONTACTED' => ['WAITING', 'CANCELLED'],
        'CONVERTED' => [],
        'CANCELLED' => [],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SmartOrderingRuntimePolicy $runtime,
    ) {}

    /**
     * @param  Collection<int, SmartOrderingBranch>  $branches
     * @return array<string,mixed>
     */
    public function workspace(
        Collection $branches,
        ?string $requestedBranch,
        User $actor,
        string $tab,
    ): array {
        $selected = $requestedBranch
            ? $branches->first(fn (SmartOrderingBranch $branch): bool => $branch->public_id === $requestedBranch)
            : $branches->first();

        if ($requestedBranch !== null && ! $selected instanceof SmartOrderingBranch) {
            abort(404);
        }

        if (! $selected instanceof SmartOrderingBranch) {
            return [
                'selected_branch' => null,
                'tables' => [],
                'records' => null,
                'can_manage' => false,
                'module_enabled' => false,
            ];
        }

        $selected->loadMissing('site');
        $moduleEnabled = $this->runtime->entitled($selected->site, Module::RESERVATIONS, $selected);
        $canManage = $actor->hasPermission('orders.manage', (int) $selected->store_id)
            || $actor->hasPermission('orders.manage');

        if (! $moduleEnabled) {
            return [
                'selected_branch' => $selected,
                'tables' => [],
                'records' => null,
                'can_manage' => false,
                'module_enabled' => false,
            ];
        }

        $branchId = (int) $selected->getKey();
        $tables = DB::table('smart_ordering_tables')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['public_id', 'label', 'capacity'])
            ->map(fn (object $table): array => [
                'public_id' => (string) $table->public_id,
                'label' => (string) $table->label,
                'capacity' => (int) $table->capacity,
            ])
            ->all();

        $records = match ($tab) {
            'waitlist' => $this->waitlistPage($selected),
            'guest_book' => $this->guestBookPage($selected),
            default => $this->reservationPage($selected),
        };

        return [
            'selected_branch' => $selected,
            'tables' => $tables,
            'records' => $records,
            'can_manage' => $canManage,
            'module_enabled' => true,
        ];
    }

    /** @param array<string,mixed> $data */
    public function createReservation(SmartOrderingBranch $branch, array $data, User $actor): string
    {
        $this->assertOperational($branch);

        return DB::transaction(function () use ($branch, $data, $actor): string {
            $serviceAt = $this->serviceAtUtc($branch, (string) $data['service_at']);
            $table = $this->optionalTable($branch, $data['table_public_id'] ?? null);

            if ($table !== null) {
                $this->assertCapacity($table, (int) $data['party_size']);
                $this->assertReservationConflict($branch, (int) $table->id, $serviceAt);
            }

            $publicId = (string) Str::uuid();
            $customerId = $this->matchedCustomerId($branch, $data);
            DB::table('smart_ordering_reservations')->insert([
                'public_id' => $publicId,
                'branch_id' => (int) $branch->getKey(),
                'customer_id' => $customerId,
                'table_id' => $table?->id,
                'guest_name' => trim((string) $data['guest_name']),
                'guest_phone' => $this->cleanNullable($data['guest_phone'] ?? null),
                'guest_email' => $this->normalizedEmail($data['guest_email'] ?? null),
                'party_size' => (int) $data['party_size'],
                'service_at' => $serviceAt,
                'status' => 'BOOKED',
                'notes' => $this->cleanNullable($data['notes'] ?? null),
                'created_by' => (int) $actor->getKey(),
                'updated_by' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditEvent('smart_ordering.reservation.created', $branch, $actor, [
                'reservation_public_id' => $publicId,
                'status' => 'BOOKED',
                'party_size' => (int) $data['party_size'],
                'table_public_id' => $table?->public_id,
                'customer_linked' => $customerId !== null,
            ]);

            return $publicId;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateReservation(
        SmartOrderingBranch $branch,
        string $publicId,
        array $data,
        User $actor,
    ): void {
        $this->assertOperational($branch);

        DB::transaction(function () use ($branch, $publicId, $data, $actor): void {
            $record = $this->reservation($branch, $publicId, true);
            if (in_array((string) $record->status, ['COMPLETED', 'CANCELLED', 'NO_SHOW'], true)) {
                throw ValidationException::withMessages([
                    'reservation' => [__('smart_ordering.guest_service.errors.terminal_reservation')],
                ]);
            }

            $serviceAt = $this->serviceAtUtc($branch, (string) $data['service_at']);
            $table = $this->optionalTable($branch, $data['table_public_id'] ?? null);
            if ($table !== null) {
                $this->assertCapacity($table, (int) $data['party_size']);
                $this->assertReservationConflict(
                    $branch,
                    (int) $table->id,
                    $serviceAt,
                    (int) $record->id,
                );
                if ((string) $record->status === 'SEATED') {
                    $this->assertTableAvailableForSeating($branch, (int) $table->id, (int) $record->id);
                }
            }

            $before = [
                'status' => (string) $record->status,
                'table_id' => $record->table_id,
                'service_at' => (string) $record->service_at,
                'party_size' => (int) $record->party_size,
            ];
            $customerId = $this->matchedCustomerId($branch, $data);

            DB::table('smart_ordering_reservations')
                ->where('id', $record->id)
                ->update([
                    'customer_id' => $customerId,
                    'table_id' => $table?->id,
                    'guest_name' => trim((string) $data['guest_name']),
                    'guest_phone' => $this->cleanNullable($data['guest_phone'] ?? null),
                    'guest_email' => $this->normalizedEmail($data['guest_email'] ?? null),
                    'party_size' => (int) $data['party_size'],
                    'service_at' => $serviceAt,
                    'notes' => $this->cleanNullable($data['notes'] ?? null),
                    'updated_by' => (int) $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent('smart_ordering.reservation.updated', $branch, $actor, [
                'reservation_public_id' => $publicId,
                'before' => $before,
                'table_public_id' => $table?->public_id,
                'service_at' => $serviceAt,
                'party_size' => (int) $data['party_size'],
                'customer_linked' => $customerId !== null,
            ]);
        });
    }

    public function transitionReservation(
        SmartOrderingBranch $branch,
        string $publicId,
        string $target,
        User $actor,
    ): void {
        $this->assertOperational($branch);

        DB::transaction(function () use ($branch, $publicId, $target, $actor): void {
            $record = $this->reservation($branch, $publicId, true);
            $current = (string) $record->status;
            if (! in_array($target, self::RESERVATION_GRAPH[$current] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('smart_ordering.guest_service.errors.invalid_transition')],
                ]);
            }

            if ($target === 'SEATED') {
                if ($record->table_id === null) {
                    throw ValidationException::withMessages([
                        'table_public_id' => [__('smart_ordering.guest_service.errors.table_required')],
                    ]);
                }
                $this->assertTableAvailableForSeating(
                    $branch,
                    (int) $record->table_id,
                    (int) $record->id,
                );
            }

            $timestamps = match ($target) {
                'SEATED' => ['seated_at' => now()],
                'COMPLETED' => ['completed_at' => now()],
                'CANCELLED' => ['cancelled_at' => now()],
                'NO_SHOW' => ['no_show_at' => now()],
                default => [],
            };

            DB::table('smart_ordering_reservations')
                ->where('id', $record->id)
                ->update([
                    'status' => $target,
                    ...$timestamps,
                    'updated_by' => (int) $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent('smart_ordering.reservation.transitioned', $branch, $actor, [
                'reservation_public_id' => $publicId,
                'from' => $current,
                'to' => $target,
            ]);
        });
    }

    /** @param array<string,mixed> $data */
    public function createWaitlist(SmartOrderingBranch $branch, array $data, User $actor): string
    {
        $this->assertOperational($branch);

        return DB::transaction(function () use ($branch, $data, $actor): string {
            $publicId = (string) Str::uuid();
            $customerId = $this->matchedCustomerId($branch, $data);
            DB::table('smart_ordering_waitlist_entries')->insert([
                'public_id' => $publicId,
                'branch_id' => (int) $branch->getKey(),
                'customer_id' => $customerId,
                'table_id' => null,
                'reservation_id' => null,
                'guest_name' => trim((string) $data['guest_name']),
                'guest_phone' => $this->cleanNullable($data['guest_phone'] ?? null),
                'guest_email' => $this->normalizedEmail($data['guest_email'] ?? null),
                'party_size' => (int) $data['party_size'],
                'arrived_at' => now(),
                'quoted_wait_minutes' => $data['quoted_wait_minutes'] ?? null,
                'priority' => (int) ($data['priority'] ?? 0),
                'priority_reason' => $this->cleanNullable($data['priority_reason'] ?? null),
                'status' => 'WAITING',
                'notes' => $this->cleanNullable($data['notes'] ?? null),
                'created_by' => (int) $actor->getKey(),
                'updated_by' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditEvent('smart_ordering.waitlist.created', $branch, $actor, [
                'waitlist_public_id' => $publicId,
                'status' => 'WAITING',
                'party_size' => (int) $data['party_size'],
                'customer_linked' => $customerId !== null,
            ]);

            return $publicId;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateWaitlist(
        SmartOrderingBranch $branch,
        string $publicId,
        array $data,
        User $actor,
    ): void {
        $this->assertOperational($branch);

        DB::transaction(function () use ($branch, $publicId, $data, $actor): void {
            $record = $this->waitlist($branch, $publicId, true);
            if (in_array((string) $record->status, ['CONVERTED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages([
                    'waitlist' => [__('smart_ordering.guest_service.errors.terminal_waitlist')],
                ]);
            }

            $customerId = $this->matchedCustomerId($branch, $data);
            DB::table('smart_ordering_waitlist_entries')
                ->where('id', $record->id)
                ->update([
                    'customer_id' => $customerId,
                    'guest_name' => trim((string) $data['guest_name']),
                    'guest_phone' => $this->cleanNullable($data['guest_phone'] ?? null),
                    'guest_email' => $this->normalizedEmail($data['guest_email'] ?? null),
                    'party_size' => (int) $data['party_size'],
                    'quoted_wait_minutes' => $data['quoted_wait_minutes'] ?? null,
                    'priority' => (int) ($data['priority'] ?? 0),
                    'priority_reason' => $this->cleanNullable($data['priority_reason'] ?? null),
                    'notes' => $this->cleanNullable($data['notes'] ?? null),
                    'updated_by' => (int) $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent('smart_ordering.waitlist.updated', $branch, $actor, [
                'waitlist_public_id' => $publicId,
                'status' => (string) $record->status,
                'party_size' => (int) $data['party_size'],
                'priority' => (int) ($data['priority'] ?? 0),
                'customer_linked' => $customerId !== null,
            ]);
        });
    }

    public function transitionWaitlist(
        SmartOrderingBranch $branch,
        string $publicId,
        string $target,
        User $actor,
    ): void {
        $this->assertOperational($branch);

        DB::transaction(function () use ($branch, $publicId, $target, $actor): void {
            $record = $this->waitlist($branch, $publicId, true);
            $current = (string) $record->status;
            if (! in_array($target, self::WAITLIST_GRAPH[$current] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('smart_ordering.guest_service.errors.invalid_transition')],
                ]);
            }

            $timestamps = match ($target) {
                'CONTACTED' => ['contacted_at' => now()],
                'CANCELLED' => ['cancelled_at' => now()],
                default => [],
            };
            DB::table('smart_ordering_waitlist_entries')
                ->where('id', $record->id)
                ->update([
                    'status' => $target,
                    ...$timestamps,
                    'updated_by' => (int) $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent('smart_ordering.waitlist.transitioned', $branch, $actor, [
                'waitlist_public_id' => $publicId,
                'from' => $current,
                'to' => $target,
            ]);
        });
    }

    /**
     * Convert a waitlist entry exactly once. Retrying the same operation returns the
     * already-created reservation instead of creating a duplicate.
     */
    public function convertWaitlist(
        SmartOrderingBranch $branch,
        string $publicId,
        ?string $tablePublicId,
        bool $seatNow,
        User $actor,
    ): string {
        $this->assertOperational($branch);

        return DB::transaction(function () use ($branch, $publicId, $tablePublicId, $seatNow, $actor): string {
            $wait = $this->waitlist($branch, $publicId, true);

            if ($wait->reservation_id !== null) {
                $existing = DB::table('smart_ordering_reservations')
                    ->where('branch_id', (int) $branch->getKey())
                    ->where('id', (int) $wait->reservation_id)
                    ->value('public_id');
                abort_unless(is_string($existing), 409);

                return $existing;
            }

            if ((string) $wait->status === 'CANCELLED') {
                throw ValidationException::withMessages([
                    'waitlist' => [__('smart_ordering.guest_service.errors.terminal_waitlist')],
                ]);
            }

            $table = $this->optionalTable($branch, $tablePublicId);
            if ($seatNow && $table === null) {
                throw ValidationException::withMessages([
                    'table_public_id' => [__('smart_ordering.guest_service.errors.table_required')],
                ]);
            }
            if ($table !== null) {
                $this->assertCapacity($table, (int) $wait->party_size);
                $serviceAt = now()->utc()->format('Y-m-d H:i:s');
                $this->assertReservationConflict($branch, (int) $table->id, $serviceAt);
                if ($seatNow) {
                    $this->assertTableAvailableForSeating($branch, (int) $table->id);
                }
            }

            $reservationPublicId = (string) Str::uuid();
            $reservationId = (int) DB::table('smart_ordering_reservations')->insertGetId([
                'public_id' => $reservationPublicId,
                'branch_id' => (int) $branch->getKey(),
                'customer_id' => $wait->customer_id,
                'table_id' => $table?->id,
                'guest_name' => (string) $wait->guest_name,
                'guest_phone' => $wait->guest_phone,
                'guest_email' => $wait->guest_email,
                'party_size' => (int) $wait->party_size,
                'service_at' => now()->utc(),
                'status' => $seatNow ? 'SEATED' : 'CONFIRMED',
                'notes' => $wait->notes,
                'seated_at' => $seatNow ? now() : null,
                'created_by' => (int) $actor->getKey(),
                'updated_by' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_waitlist_entries')
                ->where('id', $wait->id)
                ->update([
                    'table_id' => $table?->id,
                    'reservation_id' => $reservationId,
                    'status' => 'CONVERTED',
                    'converted_at' => now(),
                    'updated_by' => (int) $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent('smart_ordering.waitlist.converted', $branch, $actor, [
                'waitlist_public_id' => $publicId,
                'reservation_public_id' => $reservationPublicId,
                'table_public_id' => $table?->public_id,
                'seat_now' => $seatNow,
            ]);

            return $reservationPublicId;
        });
    }

    private function reservationPage(SmartOrderingBranch $branch): LengthAwarePaginator
    {
        $paginator = DB::table('smart_ordering_reservations as r')
            ->leftJoin('smart_ordering_tables as t', 't.id', '=', 'r.table_id')
            ->where('r.branch_id', (int) $branch->getKey())
            ->orderByRaw("CASE WHEN r.status IN ('BOOKED','CONFIRMED','SEATED') THEN 0 ELSE 1 END")
            ->orderBy('r.service_at')
            ->orderByDesc('r.id')
            ->paginate(25, [
                'r.id', 'r.public_id', 'r.customer_id', 'r.guest_name', 'r.guest_phone',
                'r.guest_email', 'r.party_size', 'r.service_at', 'r.status', 'r.notes',
                'r.table_id', 't.public_id as table_public_id', 't.label as table_label',
            ], 'reservations_page')
            ->withQueryString();

        $timezone = (string) ($branch->timezone ?: 'Asia/Kuwait');
        $paginator->setCollection($paginator->getCollection()->map(function (object $row) use ($timezone): array {
            $status = (string) $row->status;

            return [
                ...((array) $row),
                'service_display' => CarbonImmutable::parse((string) $row->service_at, 'UTC')
                    ->setTimezone($timezone)->format('Y-m-d H:i'),
                'service_input' => CarbonImmutable::parse((string) $row->service_at, 'UTC')
                    ->setTimezone($timezone)->format('Y-m-d\\TH:i'),
                'allowed_targets' => self::RESERVATION_GRAPH[$status] ?? [],
                'customer_linked' => $row->customer_id !== null,
            ];
        }));

        return $paginator;
    }

    private function waitlistPage(SmartOrderingBranch $branch): LengthAwarePaginator
    {
        $paginator = DB::table('smart_ordering_waitlist_entries as w')
            ->leftJoin('smart_ordering_tables as t', 't.id', '=', 'w.table_id')
            ->leftJoin('smart_ordering_reservations as r', 'r.id', '=', 'w.reservation_id')
            ->where('w.branch_id', (int) $branch->getKey())
            ->orderByRaw("CASE WHEN w.status IN ('WAITING','CONTACTED') THEN 0 ELSE 1 END")
            ->orderByDesc('w.priority')
            ->orderBy('w.arrived_at')
            ->paginate(25, [
                'w.id', 'w.public_id', 'w.customer_id', 'w.guest_name', 'w.guest_phone',
                'w.guest_email', 'w.party_size', 'w.arrived_at', 'w.quoted_wait_minutes',
                'w.priority', 'w.priority_reason', 'w.status', 'w.notes', 'w.table_id',
                't.public_id as table_public_id', 't.label as table_label',
                'r.public_id as reservation_public_id',
            ], 'waitlist_page')
            ->withQueryString();

        $timezone = (string) ($branch->timezone ?: 'Asia/Kuwait');
        $paginator->setCollection($paginator->getCollection()->map(function (object $row) use ($timezone): array {
            $status = (string) $row->status;

            return [
                ...((array) $row),
                'arrival_display' => CarbonImmutable::parse((string) $row->arrived_at, 'UTC')
                    ->setTimezone($timezone)->format('Y-m-d H:i'),
                'allowed_targets' => self::WAITLIST_GRAPH[$status] ?? [],
                'customer_linked' => $row->customer_id !== null,
            ];
        }));

        return $paginator;
    }

    private function guestBookPage(SmartOrderingBranch $branch): LengthAwarePaginator
    {
        $reservations = DB::table('smart_ordering_reservations')
            ->where('branch_id', (int) $branch->getKey())
            ->select([
                'customer_id',
                'guest_name',
                'guest_phone',
                'guest_email',
                DB::raw('created_at as last_seen_at'),
                DB::raw("'reservation' as interaction_type"),
            ]);

        $waitlist = DB::table('smart_ordering_waitlist_entries')
            ->where('branch_id', (int) $branch->getKey())
            ->select([
                'customer_id',
                'guest_name',
                'guest_phone',
                'guest_email',
                DB::raw('created_at as last_seen_at'),
                DB::raw("'waitlist' as interaction_type"),
            ]);

        $events = $reservations->unionAll($waitlist);

        $paginator = DB::query()
            ->fromSub($events, 'guest_events')
            ->select([
                'customer_id',
                'guest_name',
                'guest_phone',
                'guest_email',
                DB::raw('MAX(last_seen_at) as last_seen_at'),
                DB::raw("SUM(CASE WHEN interaction_type = 'reservation' THEN 1 ELSE 0 END) as reservation_count"),
                DB::raw("SUM(CASE WHEN interaction_type = 'waitlist' THEN 1 ELSE 0 END) as waitlist_count"),
            ])
            ->groupBy('customer_id', 'guest_name', 'guest_phone', 'guest_email')
            ->orderByDesc('last_seen_at')
            ->paginate(25, ['*'], 'guest_book_page')
            ->withQueryString();

        $timezone = (string) ($branch->timezone ?: 'Asia/Kuwait');
        $paginator->setCollection($paginator->getCollection()->map(
            static fn (object $row): array => [
                'guest_name' => (string) $row->guest_name,
                'guest_phone' => $row->guest_phone,
                'guest_email' => $row->guest_email,
                'customer_linked' => $row->customer_id !== null,
                'reservation_count' => (int) $row->reservation_count,
                'waitlist_count' => (int) $row->waitlist_count,
                'last_seen_display' => $row->last_seen_at === null
                    ? '—'
                    : CarbonImmutable::parse((string) $row->last_seen_at, 'UTC')
                        ->setTimezone($timezone)
                        ->format('Y-m-d H:i'),
            ],
        ));

        return $paginator;
    }

    private function serviceAtUtc(SmartOrderingBranch $branch, string $value): string
    {
        $timezone = (string) ($branch->timezone ?: 'Asia/Kuwait');

        $serviceAt = CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $value, $timezone)->utc();
        if ($serviceAt->lt(now()->utc()->subMinutes(5))) {
            throw ValidationException::withMessages([
                'service_at' => [__('smart_ordering.guest_service.errors.service_time_past')],
            ]);
        }

        return $serviceAt->format('Y-m-d H:i:s');
    }

    private function optionalTable(SmartOrderingBranch $branch, mixed $publicId): ?object
    {
        if (! is_string($publicId) || trim($publicId) === '') {
            return null;
        }

        $table = DB::table('smart_ordering_tables')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', trim($publicId))
            ->where('is_active', true)
            ->first(['id', 'public_id', 'label', 'capacity']);

        abort_unless($table !== null, 404);

        return $table;
    }

    private function assertCapacity(object $table, int $partySize): void
    {
        if ($partySize > (int) $table->capacity) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.guest_service.errors.capacity')],
            ]);
        }
    }

    private function assertReservationConflict(
        SmartOrderingBranch $branch,
        int $tableId,
        string $serviceAt,
        ?int $ignoreReservationId = null,
    ): void {
        $at = CarbonImmutable::parse($serviceAt, 'UTC');
        $query = DB::table('smart_ordering_reservations')
            ->where('branch_id', (int) $branch->getKey())
            ->where('table_id', $tableId)
            ->whereIn('status', self::RESERVATION_ACTIVE)
            ->whereBetween('service_at', [
                $at->subMinutes(119)->format('Y-m-d H:i:s'),
                $at->addMinutes(119)->format('Y-m-d H:i:s'),
            ]);

        if ($ignoreReservationId !== null) {
            $query->where('id', '!=', $ignoreReservationId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.guest_service.errors.table_conflict')],
            ]);
        }
    }

    private function assertTableAvailableForSeating(
        SmartOrderingBranch $branch,
        int $tableId,
        ?int $ignoreReservationId = null,
    ): void {
        $activeOrder = DB::table('smart_ordering_order_contexts')
            ->where('branch_id', (int) $branch->getKey())
            ->where('table_id', $tableId)
            ->where('fulfillment_mode', 'dine_in')
            ->whereNotIn('lifecycle_status', ['COMPLETED', 'CANCELLED'])
            ->exists();

        $seated = DB::table('smart_ordering_reservations')
            ->where('branch_id', (int) $branch->getKey())
            ->where('table_id', $tableId)
            ->where('status', 'SEATED')
            ->when($ignoreReservationId !== null, fn ($query) => $query->where('id', '!=', $ignoreReservationId))
            ->exists();

        if ($activeOrder || $seated) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.guest_service.errors.table_occupied')],
            ]);
        }
    }

    private function reservation(SmartOrderingBranch $branch, string $publicId, bool $lock = false): object
    {
        $query = DB::table('smart_ordering_reservations')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $record = $query->first();
        abort_unless($record !== null, 404);

        return $record;
    }

    private function waitlist(SmartOrderingBranch $branch, string $publicId, bool $lock = false): object
    {
        $query = DB::table('smart_ordering_waitlist_entries')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $record = $query->first();
        abort_unless($record !== null, 404);

        return $record;
    }

    /** @param array<string,mixed> $data */
    private function matchedCustomerId(SmartOrderingBranch $branch, array $data): ?int
    {
        $phone = $this->cleanNullable($data['guest_phone'] ?? null);
        $email = $this->normalizedEmail($data['guest_email'] ?? null);
        if ($phone === null && $email === null) {
            return null;
        }

        $query = DB::table('b2c_customers')
            ->where('store_id', (int) $branch->store_id)
            ->whereNotNull('legacy_customer_id')
            ->where(function ($query) use ($phone, $email): void {
                if ($phone !== null) {
                    $query->where('phone', $phone);
                }
                if ($email !== null) {
                    $phone === null
                        ? $query->where('email', $email)
                        : $query->orWhere('email', $email);
                }
            })
            ->orderBy('id');

        $matches = $query
            ->pluck('legacy_customer_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->take(2)
            ->values();

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    private function assertOperational(SmartOrderingBranch $branch): void
    {
        $branch->loadMissing('site');
        abort_unless($branch->is_active, 404);
        abort_unless(
            $this->runtime->entitled($branch->site, Module::RESERVATIONS, $branch),
            404,
        );
    }

    private function cleanNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizedEmail(mixed $value): ?string
    {
        $value = $this->cleanNullable($value);

        return $value === null ? null : Str::lower($value);
    }

    /** @param array<string,mixed> $data */
    private function auditEvent(string $event, SmartOrderingBranch $branch, User $actor, array $data): void
    {
        $this->audit->record($event, $actor, null, null, [
            'store_id' => (int) $branch->store_id,
            'branch_public_id' => (string) $branch->public_id,
            ...$data,
        ]);
    }
}
