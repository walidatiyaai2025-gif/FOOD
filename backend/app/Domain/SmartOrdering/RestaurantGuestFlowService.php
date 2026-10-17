<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Canonical front-of-house reservation/waitlist composition for Restaurant SaaS.
 *
 * Guest profiles are restaurant-scoped operational identities. They link to the
 * existing customer master only when one unambiguous B2C customer is visible in
 * the same store; they never create or merge platform/customer records.
 */
final class RestaurantGuestFlowService
{
    public function __construct(
        private readonly SmartOrderingRuntimePolicy $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param Collection<int, SmartOrderingBranch> $branches
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function workspace(
        Collection $branches,
        ?string $requestedBranch,
        User $actor,
        array $filters = [],
    ): array {
        $selected = $requestedBranch
            ? $branches->first(fn (SmartOrderingBranch $branch): bool => $branch->public_id === $requestedBranch)
            : $branches->first();

        if ($requestedBranch !== null && ! $selected instanceof SmartOrderingBranch) {
            abort(404);
        }

        if (! $selected instanceof SmartOrderingBranch) {
            return $this->emptyWorkspace();
        }

        $selected->loadMissing('site');
        $moduleEnabled = $this->runtime->entitled($selected->site, Module::RESERVATIONS, $selected);
        $canManage = $this->canManage($actor, $selected);

        if (! $moduleEnabled) {
            return [
                ...$this->emptyWorkspace(),
                'selected_branch' => $selected,
                'module_enabled' => false,
                'can_manage' => $canManage,
            ];
        }

        $reservationQuery = DB::table('smart_ordering_reservations as r')
            ->join('smart_ordering_guest_profiles as g', 'g.id', '=', 'r.guest_profile_id')
            ->leftJoin('smart_ordering_tables as t', 't.id', '=', 'r.table_id')
            ->where('r.branch_id', $selected->getKey())
            ->when(
                isset($filters['reservation_status']) && $filters['reservation_status'] !== '',
                fn ($query) => $query->where('r.status', $filters['reservation_status']),
            )
            ->orderByRaw("CASE WHEN r.status IN ('BOOKED','CONFIRMED','SEATED') THEN 0 ELSE 1 END")
            ->orderBy('r.service_at')
            ->orderByDesc('r.id');

        /** @var LengthAwarePaginator $reservations */
        $reservations = $reservationQuery->paginate(
            20,
            [
                'r.id',
                'r.public_id',
                'r.party_size',
                'r.service_at',
                'r.status',
                'r.notes',
                'r.table_id',
                'g.public_id as guest_public_id',
                'g.display_name as guest_name',
                'g.phone as guest_phone',
                'g.email as guest_email',
                'g.customer_id',
                't.public_id as table_public_id',
                't.label as table_label',
            ],
            'reservation_page',
        )->withQueryString();

        $reservations->setCollection($reservations->getCollection()->map(
            fn (object $row): array => $this->reservationRow($row, $selected, $canManage),
        ));

        $waitlistQuery = DB::table('smart_ordering_waitlist_entries as w')
            ->join('smart_ordering_guest_profiles as g', 'g.id', '=', 'w.guest_profile_id')
            ->leftJoin('smart_ordering_tables as t', 't.id', '=', 'w.table_id')
            ->leftJoin('smart_ordering_reservations as r', 'r.id', '=', 'w.reservation_id')
            ->where('w.branch_id', $selected->getKey())
            ->when(
                isset($filters['waitlist_status']) && $filters['waitlist_status'] !== '',
                fn ($query) => $query->where('w.status', $filters['waitlist_status']),
            )
            ->orderByRaw("CASE WHEN w.status IN ('WAITING','NOTIFIED') THEN 0 ELSE 1 END")
            ->orderByDesc('w.priority')
            ->orderBy('w.arrived_at')
            ->orderByDesc('w.id');

        /** @var LengthAwarePaginator $waitlist */
        $waitlist = $waitlistQuery->paginate(
            20,
            [
                'w.id',
                'w.public_id',
                'w.party_size',
                'w.arrived_at',
                'w.quoted_wait_minutes',
                'w.priority',
                'w.priority_reason',
                'w.status',
                'w.notes',
                'g.public_id as guest_public_id',
                'g.display_name as guest_name',
                'g.phone as guest_phone',
                'g.email as guest_email',
                'g.customer_id',
                't.public_id as table_public_id',
                't.label as table_label',
                'r.public_id as reservation_public_id',
            ],
            'waitlist_page',
        )->withQueryString();

        $waitlist->setCollection($waitlist->getCollection()->map(
            fn (object $row): array => $this->waitlistRow($row, $selected, $canManage),
        ));

        /** @var LengthAwarePaginator $guests */
        $guests = DB::table('smart_ordering_guest_profiles as g')
            ->where('g.site_id', $selected->site_id)
            ->orderByDesc('g.last_seen_at')
            ->orderByDesc('g.id')
            ->paginate(
                20,
                [
                    'g.public_id',
                    'g.display_name',
                    'g.phone',
                    'g.email',
                    'g.customer_id',
                    'g.last_seen_at',
                ],
                'guest_page',
            )
            ->withQueryString();

        $guests->setCollection($guests->getCollection()->map(function (object $row) use ($selected): array {
            $visits = DB::table('smart_ordering_reservations as r')
                ->join('smart_ordering_guest_profiles as gp', 'gp.id', '=', 'r.guest_profile_id')
                ->where('gp.public_id', $row->public_id)
                ->where('r.branch_id', $selected->getKey())
                ->count();

            return [
                ...((array) $row),
                'visit_count' => $visits,
                'last_seen_display' => $row->last_seen_at
                    ? Carbon::parse((string) $row->last_seen_at)->timezone($selected->timezone)->format('Y-m-d H:i')
                    : '—',
            ];
        }));

        $tables = DB::table('smart_ordering_tables')
            ->where('branch_id', $selected->getKey())
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['public_id', 'label', 'capacity'])
            ->map(static fn (object $table): array => [
                'public_id' => (string) $table->public_id,
                'label' => (string) $table->label,
                'capacity' => (int) $table->capacity,
            ])->all();

        return [
            'selected_branch' => $selected,
            'module_enabled' => true,
            'can_manage' => $canManage,
            'reservations' => $reservations,
            'waitlist' => $waitlist,
            'guests' => $guests,
            'tables' => $tables,
        ];
    }

    /** @param array<string,mixed> $data */
    public function createReservation(
        SmartOrderingBranch $branch,
        User $actor,
        array $data,
        ?Request $request = null,
    ): string {
        $this->assertOperational($branch, $actor);
        $serviceAt = $this->serviceTime($branch, (string) $data['service_at']);
        $guestId = $this->resolveGuest($branch, $data);
        $tableId = $this->tableId(
            $branch,
            $data['table_public_id'] ?? null,
            (int) $data['party_size'],
            $serviceAt,
        );

        $publicId = (string) Str::uuid();
        $reservationId = DB::transaction(function () use (
            $branch,
            $actor,
            $data,
            $guestId,
            $tableId,
            $serviceAt,
            $publicId,
        ): int {
            return (int) DB::table('smart_ordering_reservations')->insertGetId([
                'public_id' => $publicId,
                'branch_id' => (int) $branch->getKey(),
                'guest_profile_id' => $guestId,
                'table_id' => $tableId,
                'party_size' => (int) $data['party_size'],
                'service_at' => $serviceAt,
                'status' => 'BOOKED',
                'source' => 'dashboard',
                'notes' => $this->nullableText($data['notes'] ?? null),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit->record('smart_ordering.reservation.created', $actor, $branch, null, [
            'store_id' => (int) $branch->store_id,
            'reservation_public_id' => $publicId,
            'branch_public_id' => (string) $branch->public_id,
            'party_size' => (int) $data['party_size'],
            'service_at' => $serviceAt->toIso8601String(),
            'table_id' => $tableId,
            'status' => 'BOOKED',
        ], $request);

        DB::table('smart_ordering_guest_profiles')
            ->where('id', $guestId)
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);

        return $publicId;
    }

    public function transitionReservation(
        SmartOrderingBranch $branch,
        string $reservationPublicId,
        User $actor,
        string $target,
        ?string $tablePublicId = null,
        ?Request $request = null,
    ): void {
        $this->assertOperational($branch, $actor);

        DB::transaction(function () use (
            $branch,
            $reservationPublicId,
            $actor,
            $target,
            $tablePublicId,
            $request,
        ): void {
            $reservation = DB::table('smart_ordering_reservations')
                ->where('branch_id', $branch->getKey())
                ->where('public_id', $reservationPublicId)
                ->lockForUpdate()
                ->first();
            abort_unless($reservation !== null, 404);

            $allowed = $this->reservationTargets((string) $reservation->status);
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => [__('smart_ordering.reservations.errors.invalid_transition')],
                ]);
            }

            $tableId = $reservation->table_id === null ? null : (int) $reservation->table_id;
            if ($tablePublicId !== null && trim($tablePublicId) !== '') {
                $tableId = $this->tableId(
                    $branch,
                    trim($tablePublicId),
                    (int) $reservation->party_size,
                    Carbon::parse((string) $reservation->service_at),
                    (int) $reservation->id,
                );
            }

            if ($target === 'SEATED') {
                if ($tableId === null) {
                    throw ValidationException::withMessages([
                        'table_public_id' => [__('smart_ordering.reservations.errors.table_required')],
                    ]);
                }
                $this->assertTableAvailable(
                    $branch,
                    $tableId,
                    (int) $reservation->party_size,
                    now(),
                    (int) $reservation->id,
                );
            }

            $updates = [
                'status' => $target,
                'table_id' => $tableId,
                'updated_by' => $actor->getKey(),
                'updated_at' => now(),
            ];
            if ($target === 'SEATED') {
                $updates['seated_at'] = now();
            } elseif ($target === 'COMPLETED') {
                $updates['completed_at'] = now();
            } elseif ($target === 'CANCELLED') {
                $updates['cancelled_at'] = now();
            } elseif ($target === 'NO_SHOW') {
                $updates['no_show_at'] = now();
            }

            DB::table('smart_ordering_reservations')
                ->where('id', $reservation->id)
                ->update($updates);

            $this->audit->record('smart_ordering.reservation.transitioned', $actor, $branch, [
                'store_id' => (int) $branch->store_id,
                'reservation_public_id' => $reservationPublicId,
                'status' => (string) $reservation->status,
                'table_id' => $reservation->table_id,
            ], [
                'store_id' => (int) $branch->store_id,
                'reservation_public_id' => $reservationPublicId,
                'status' => $target,
                'table_id' => $tableId,
            ], $request);
        });
    }

    /** @param array<string,mixed> $data */
    public function createWaitlist(
        SmartOrderingBranch $branch,
        User $actor,
        array $data,
        ?Request $request = null,
    ): string {
        $this->assertOperational($branch, $actor);
        $guestId = $this->resolveGuest($branch, $data);
        $publicId = (string) Str::uuid();

        DB::table('smart_ordering_waitlist_entries')->insert([
            'public_id' => $publicId,
            'branch_id' => (int) $branch->getKey(),
            'guest_profile_id' => $guestId,
            'table_id' => null,
            'reservation_id' => null,
            'party_size' => (int) $data['party_size'],
            'arrived_at' => now(),
            'quoted_wait_minutes' => (int) ($data['quoted_wait_minutes'] ?? 0),
            'priority' => (int) ($data['priority'] ?? 0),
            'priority_reason' => $this->nullableText($data['priority_reason'] ?? null),
            'status' => 'WAITING',
            'notes' => $this->nullableText($data['notes'] ?? null),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('smart_ordering_guest_profiles')
            ->where('id', $guestId)
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);

        $this->audit->record('smart_ordering.waitlist.created', $actor, $branch, null, [
            'store_id' => (int) $branch->store_id,
            'waitlist_public_id' => $publicId,
            'branch_public_id' => (string) $branch->public_id,
            'party_size' => (int) $data['party_size'],
            'status' => 'WAITING',
        ], $request);

        return $publicId;
    }

    public function transitionWaitlist(
        SmartOrderingBranch $branch,
        string $waitlistPublicId,
        User $actor,
        string $target,
        ?string $tablePublicId = null,
        ?Request $request = null,
    ): void {
        $this->assertOperational($branch, $actor);

        DB::transaction(function () use (
            $branch,
            $waitlistPublicId,
            $actor,
            $target,
            $tablePublicId,
            $request,
        ): void {
            $entry = DB::table('smart_ordering_waitlist_entries')
                ->where('branch_id', $branch->getKey())
                ->where('public_id', $waitlistPublicId)
                ->lockForUpdate()
                ->first();
            abort_unless($entry !== null, 404);

            if (! in_array($target, $this->waitlistTargets((string) $entry->status), true)) {
                throw ValidationException::withMessages([
                    'status' => [__('smart_ordering.reservations.errors.invalid_waitlist_transition')],
                ]);
            }

            $tableId = $entry->table_id === null ? null : (int) $entry->table_id;
            if ($target === 'SEATED') {
                if ($tablePublicId !== null && trim($tablePublicId) !== '') {
                    $table = $this->table($branch, trim($tablePublicId));
                    $tableId = (int) $table->id;
                }
                if ($tableId === null) {
                    throw ValidationException::withMessages([
                        'table_public_id' => [__('smart_ordering.reservations.errors.table_required')],
                    ]);
                }
                $this->assertTableAvailable($branch, $tableId, (int) $entry->party_size, now());
            }

            DB::table('smart_ordering_waitlist_entries')
                ->where('id', $entry->id)
                ->update([
                    'status' => $target,
                    'table_id' => $tableId,
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->audit->record('smart_ordering.waitlist.transitioned', $actor, $branch, [
                'store_id' => (int) $branch->store_id,
                'waitlist_public_id' => $waitlistPublicId,
                'status' => (string) $entry->status,
            ], [
                'store_id' => (int) $branch->store_id,
                'waitlist_public_id' => $waitlistPublicId,
                'status' => $target,
                'table_id' => $tableId,
            ], $request);
        });
    }

    /** @param array<string,mixed> $data */
    public function convertWaitlist(
        SmartOrderingBranch $branch,
        string $waitlistPublicId,
        User $actor,
        array $data,
        ?Request $request = null,
    ): string {
        $this->assertOperational($branch, $actor);

        return DB::transaction(function () use (
            $branch,
            $waitlistPublicId,
            $actor,
            $data,
            $request,
        ): string {
            $entry = DB::table('smart_ordering_waitlist_entries')
                ->where('branch_id', $branch->getKey())
                ->where('public_id', $waitlistPublicId)
                ->lockForUpdate()
                ->first();
            abort_unless($entry !== null, 404);

            if ((string) $entry->status === 'CONVERTED' && $entry->reservation_id !== null) {
                $existing = DB::table('smart_ordering_reservations')
                    ->where('id', $entry->reservation_id)
                    ->value('public_id');
                abort_unless(is_string($existing), 409);

                return $existing;
            }

            if (! in_array((string) $entry->status, ['WAITING', 'NOTIFIED'], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('smart_ordering.reservations.errors.waitlist_not_convertible')],
                ]);
            }

            $conversionKey = trim((string) $data['conversion_key']);
            $collision = DB::table('smart_ordering_waitlist_entries')
                ->where('conversion_key', $conversionKey)
                ->where('id', '!=', $entry->id)
                ->exists();
            if ($collision) {
                throw ValidationException::withMessages([
                    'conversion_key' => [__('smart_ordering.reservations.errors.idempotency_conflict')],
                ]);
            }

            $serviceAt = $this->serviceTime($branch, (string) $data['service_at']);
            $tableId = $this->tableId(
                $branch,
                $data['table_public_id'] ?? null,
                (int) $entry->party_size,
                $serviceAt,
            );
            $reservationPublicId = (string) Str::uuid();

            $reservationId = (int) DB::table('smart_ordering_reservations')->insertGetId([
                'public_id' => $reservationPublicId,
                'branch_id' => (int) $branch->getKey(),
                'guest_profile_id' => (int) $entry->guest_profile_id,
                'table_id' => $tableId,
                'party_size' => (int) $entry->party_size,
                'service_at' => $serviceAt,
                'status' => 'BOOKED',
                'source' => 'waitlist',
                'notes' => $this->nullableText($entry->notes),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('smart_ordering_waitlist_entries')
                ->where('id', $entry->id)
                ->update([
                    'status' => 'CONVERTED',
                    'reservation_id' => $reservationId,
                    'table_id' => $tableId,
                    'conversion_key' => $conversionKey,
                    'converted_at' => now(),
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                ]);

            $this->audit->record('smart_ordering.waitlist.converted', $actor, $branch, [
                'store_id' => (int) $branch->store_id,
                'waitlist_public_id' => $waitlistPublicId,
                'status' => (string) $entry->status,
            ], [
                'store_id' => (int) $branch->store_id,
                'waitlist_public_id' => $waitlistPublicId,
                'status' => 'CONVERTED',
                'reservation_public_id' => $reservationPublicId,
                'table_id' => $tableId,
            ], $request);

            return $reservationPublicId;
        });
    }

    /** @return array<string,mixed> */
    private function emptyWorkspace(): array
    {
        return [
            'selected_branch' => null,
            'module_enabled' => false,
            'can_manage' => false,
            'reservations' => null,
            'waitlist' => null,
            'guests' => null,
            'tables' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function reservationRow(object $row, SmartOrderingBranch $branch, bool $canManage): array
    {
        return [
            ...((array) $row),
            'service_display' => Carbon::parse((string) $row->service_at)
                ->timezone($branch->timezone)
                ->format('Y-m-d H:i'),
            'allowed_targets' => $this->reservationTargets((string) $row->status),
            'can_manage' => $canManage,
        ];
    }

    /** @return array<string,mixed> */
    private function waitlistRow(object $row, SmartOrderingBranch $branch, bool $canManage): array
    {
        return [
            ...((array) $row),
            'arrived_display' => Carbon::parse((string) $row->arrived_at)
                ->timezone($branch->timezone)
                ->format('Y-m-d H:i'),
            'allowed_targets' => $this->waitlistTargets((string) $row->status),
            'can_manage' => $canManage,
        ];
    }

    /** @param array<string,mixed> $data */
    private function resolveGuest(SmartOrderingBranch $branch, array $data): int
    {
        $branch->loadMissing('site');
        $name = trim((string) $data['guest_name']);
        $phone = $this->nullableText($data['guest_phone'] ?? null);
        $email = $this->nullableText($data['guest_email'] ?? null);
        $normalizedPhone = $phone === null ? null : preg_replace('/[^0-9+]/', '', $phone);
        $normalizedEmail = $email === null ? null : mb_strtolower($email);

        $customerId = null;
        if ($normalizedPhone !== null || $normalizedEmail !== null) {
            $matches = DB::table('b2c_customers as bc')
                ->join('customers as c', 'c.id', '=', 'bc.legacy_customer_id')
                ->where('bc.store_id', $branch->site->store_id)
                ->where(function ($query) use ($normalizedPhone, $normalizedEmail): void {
                    if ($normalizedPhone !== null) {
                        $query->where('c.phone', $normalizedPhone);
                    }
                    if ($normalizedEmail !== null) {
                        $method = $normalizedPhone === null ? 'whereRaw' : 'orWhereRaw';
                        $query->{$method}('LOWER(c.email) = ?', [$normalizedEmail]);
                    }
                })
                ->distinct()
                ->pluck('bc.legacy_customer_id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            if ($matches->count() === 1) {
                $customerId = $matches->first();
            }
        }

        $identitySource = $customerId !== null
            ? 'customer:'.$customerId
            : ($normalizedPhone !== null
                ? 'phone:'.$normalizedPhone
                : ($normalizedEmail !== null ? 'email:'.$normalizedEmail : 'anonymous:'.Str::uuid()));

        $identityHash = hash('sha256', $branch->site->public_id.'|'.$identitySource);
        $existing = DB::table('smart_ordering_guest_profiles')
            ->where('site_id', $branch->site_id)
            ->where('identity_hash', $identityHash)
            ->first();

        if ($existing !== null) {
            DB::table('smart_ordering_guest_profiles')
                ->where('id', $existing->id)
                ->update([
                    'customer_id' => $existing->customer_id ?? $customerId,
                    'display_name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table('smart_ordering_guest_profiles')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => (int) $branch->site_id,
            'customer_id' => $customerId,
            'identity_hash' => $identityHash,
            'display_name' => $name,
            'phone' => $phone,
            'email' => $email,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tableId(
        SmartOrderingBranch $branch,
        mixed $tablePublicId,
        int $partySize,
        Carbon $serviceAt,
        ?int $ignoreReservationId = null,
    ): ?int {
        if (! is_string($tablePublicId) || trim($tablePublicId) === '') {
            return null;
        }

        $table = $this->table($branch, trim($tablePublicId));
        $this->assertTableAvailable(
            $branch,
            (int) $table->id,
            $partySize,
            $serviceAt,
            $ignoreReservationId,
        );

        return (int) $table->id;
    }

    private function table(SmartOrderingBranch $branch, string $publicId): object
    {
        $table = DB::table('smart_ordering_tables')
            ->where('branch_id', $branch->getKey())
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->first(['id', 'public_id', 'label', 'capacity']);

        if ($table === null) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.reservations.errors.table_unavailable')],
            ]);
        }

        return $table;
    }

    private function assertTableAvailable(
        SmartOrderingBranch $branch,
        int $tableId,
        int $partySize,
        Carbon $serviceAt,
        ?int $ignoreReservationId = null,
    ): void {
        $table = DB::table('smart_ordering_tables')
            ->where('branch_id', $branch->getKey())
            ->where('id', $tableId)
            ->where('is_active', true)
            ->first(['id', 'capacity']);
        if ($table === null || (int) $table->capacity < $partySize) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.reservations.errors.table_unavailable')],
            ]);
        }

        $occupiedByOrder = DB::table('smart_ordering_order_contexts')
            ->where('branch_id', $branch->getKey())
            ->where('table_id', $tableId)
            ->where('fulfillment_mode', 'dine_in')
            ->whereNotIn('lifecycle_status', ['COMPLETED', 'CANCELLED'])
            ->exists();
        if ($occupiedByOrder) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.reservations.errors.table_occupied')],
            ]);
        }

        $turnMinutes = max(15, (int) config('smart_ordering.reservations.turn_minutes', 120));
        $from = $serviceAt->copy()->subMinutes($turnMinutes);
        $to = $serviceAt->copy()->addMinutes($turnMinutes);
        $conflict = DB::table('smart_ordering_reservations')
            ->where('branch_id', $branch->getKey())
            ->where('table_id', $tableId)
            ->whereIn('status', ['BOOKED', 'CONFIRMED', 'SEATED'])
            ->when(
                $ignoreReservationId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreReservationId),
            )
            ->where(function ($query) use ($from, $to): void {
                $query->where('status', 'SEATED')
                    ->orWhereBetween('service_at', [$from, $to]);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'table_public_id' => [__('smart_ordering.reservations.errors.table_conflict')],
            ]);
        }
    }

    private function assertOperational(SmartOrderingBranch $branch, User $actor): void
    {
        $branch->loadMissing('site');
        abort_unless($branch->is_active, 404);
        abort_unless($this->canManage($actor, $branch), 403);
        abort_unless($this->runtime->entitled($branch->site, Module::RESERVATIONS, $branch), 404);
    }

    private function canManage(User $actor, SmartOrderingBranch $branch): bool
    {
        return $actor->hasPermission('orders.manage', (int) $branch->store_id)
            || $actor->hasPermission('orders.manage');
    }

    private function serviceTime(SmartOrderingBranch $branch, string $value): Carbon
    {
        $time = Carbon::parse($value, $branch->timezone)->utc();
        if ($time->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages([
                'service_at' => [__('smart_ordering.reservations.errors.service_time_past')],
            ]);
        }

        return $time;
    }

    /** @return list<string> */
    private function reservationTargets(string $status): array
    {
        return match ($status) {
            'BOOKED' => ['CONFIRMED', 'SEATED', 'CANCELLED', 'NO_SHOW'],
            'CONFIRMED' => ['SEATED', 'CANCELLED', 'NO_SHOW'],
            'SEATED' => ['COMPLETED', 'CANCELLED'],
            default => [],
        };
    }

    /** @return list<string> */
    private function waitlistTargets(string $status): array
    {
        return match ($status) {
            'WAITING' => ['NOTIFIED', 'SEATED', 'CANCELLED', 'NO_SHOW'],
            'NOTIFIED' => ['WAITING', 'SEATED', 'CANCELLED', 'NO_SHOW'],
            default => [],
        };
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
