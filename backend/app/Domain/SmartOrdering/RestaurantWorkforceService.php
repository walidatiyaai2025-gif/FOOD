<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RestaurantWorkforceService
{
    /**
     * @param  Collection<int, SmartOrderingBranch>  $branches
     * @return array<string,mixed>
     */
    public function workspace(Collection $branches, ?string $requestedBranch, User $actor): array
    {
        $selected = $requestedBranch
            ? $branches->first(fn (SmartOrderingBranch $branch): bool => $branch->public_id === $requestedBranch)
            : $branches->first();

        if ($requestedBranch !== null && ! $selected instanceof SmartOrderingBranch) {
            abort(404);
        }

        if (! $selected instanceof SmartOrderingBranch) {
            return $this->emptyWorkspace();
        }

        $branchId = (int) $selected->getKey();
        $storeId = (int) $selected->store_id;
        $timezone = (string) ($selected->timezone ?: 'UTC');
        $canManage = $actor->hasPermission('orders.manage', $storeId) || $actor->hasPermission('orders.manage');

        $identityRows = DB::table('user_store_roles as usr')
            ->join('users as u', 'u.id', '=', 'usr.user_id')
            ->join('roles as r', 'r.id', '=', 'usr.role_id')
            ->where('usr.store_id', $storeId)
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->whereIn('r.scope', ['store', 'both'])
            ->orderBy('u.name')
            ->orderBy('r.name')
            ->get([
                'u.id as user_id',
                'u.name',
                'u.email',
                'r.code as role_code',
                'r.name as role_name',
            ]);

        $identities = $identityRows
            ->groupBy('user_id')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'user_id' => (int) $first->user_id,
                    'name' => (string) $first->name,
                    'email' => (string) $first->email,
                    'roles' => $rows->map(fn (object $row): array => [
                        'code' => (string) $row->role_code,
                        'label' => $this->roleLabel((string) $row->role_code, (string) $row->role_name),
                    ])->values()->all(),
                ];
            })
            ->values();

        $assignments = DB::table('restaurant_staff_assignments as rsa')
            ->join('users as u', 'u.id', '=', 'rsa.user_id')
            ->where('rsa.branch_id', $branchId)
            ->where('rsa.is_active', true)
            ->orderBy('u.name')
            ->get([
                'rsa.id',
                'rsa.user_id',
                'rsa.created_at',
                'u.name',
                'u.email',
            ])
            ->map(function (object $assignment) use ($identities): array {
                $identity = $identities->firstWhere('user_id', (int) $assignment->user_id);

                return [
                    ...((array) $assignment),
                    'roles' => (array) ($identity['roles'] ?? []),
                ];
            })
            ->values();

        $assignedUserIds = $assignments->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        $shifts = DB::table('restaurant_shifts as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.branch_id', $branchId)
            ->where('s.ends_at', '>=', now()->subDays(30))
            ->orderBy('s.starts_at')
            ->limit(120)
            ->get([
                's.id',
                's.public_id',
                's.user_id',
                's.starts_at',
                's.ends_at',
                's.status',
                's.notes',
                'u.name as user_name',
                'u.email as user_email',
            ])
            ->map(fn (object $shift): array => [
                ...((array) $shift),
                'starts_local' => $this->displayLocal((string) $shift->starts_at, $timezone),
                'ends_local' => $this->displayLocal((string) $shift->ends_at, $timezone),
                'starts_input' => $this->inputLocal((string) $shift->starts_at, $timezone),
                'ends_input' => $this->inputLocal((string) $shift->ends_at, $timezone),
            ])
            ->all();

        $sessions = DB::table('restaurant_time_clock_sessions as tc')
            ->join('users as u', 'u.id', '=', 'tc.user_id')
            ->leftJoin('restaurant_shifts as s', 's.id', '=', 'tc.shift_id')
            ->where('tc.branch_id', $branchId)
            ->orderByDesc('tc.clocked_in_at')
            ->limit(100)
            ->get([
                'tc.id',
                'tc.public_id',
                'tc.user_id',
                'tc.status',
                'tc.clocked_in_at',
                'tc.break_started_at',
                'tc.total_break_seconds',
                'tc.clocked_out_at',
                'tc.correction_note',
                'u.name as user_name',
                'u.email as user_email',
                's.public_id as shift_public_id',
            ])
            ->map(fn (object $session): array => [
                ...((array) $session),
                'clocked_in_local' => $this->displayLocal((string) $session->clocked_in_at, $timezone),
                'clocked_out_local' => $session->clocked_out_at
                    ? $this->displayLocal((string) $session->clocked_out_at, $timezone)
                    : null,
                'clocked_in_input' => $this->inputLocal((string) $session->clocked_in_at, $timezone),
                'clocked_out_input' => $session->clocked_out_at
                    ? $this->inputLocal((string) $session->clocked_out_at, $timezone)
                    : null,
                'total_break_minutes' => (int) floor(((int) $session->total_break_seconds) / 60),
            ])
            ->all();

        $currentSession = DB::table('restaurant_time_clock_sessions')
            ->where('branch_id', $branchId)
            ->where('user_id', $actor->getKey())
            ->whereIn('status', ['OPEN', 'ON_BREAK'])
            ->orderByDesc('id')
            ->first();

        $actorAssigned = in_array((int) $actor->getKey(), $assignedUserIds, true);

        return [
            'selected_branch' => $selected,
            'timezone' => $timezone,
            'can_manage' => $canManage,
            'actor_assigned' => $actorAssigned,
            'available_staff' => $identities
                ->reject(fn (array $identity): bool => in_array((int) $identity['user_id'], $assignedUserIds, true))
                ->values()
                ->all(),
            'assignments' => $assignments->all(),
            'shifts' => $shifts,
            'sessions' => $sessions,
            'current_session' => $currentSession,
        ];
    }

    public function assignStaff(SmartOrderingBranch $branch, int $userId, User $actor): void
    {
        $hasStoreIdentity = DB::table('user_store_roles as usr')
            ->join('users as u', 'u.id', '=', 'usr.user_id')
            ->join('roles as r', 'r.id', '=', 'usr.role_id')
            ->where('usr.store_id', (int) $branch->store_id)
            ->where('usr.user_id', $userId)
            ->where('u.is_active', true)
            ->where('r.is_active', true)
            ->whereIn('r.scope', ['store', 'both'])
            ->exists();

        if (! $hasStoreIdentity) {
            throw ValidationException::withMessages([
                'user_id' => [__('smart_ordering.workforce.errors.user_not_in_store')],
            ]);
        }

        DB::table('restaurant_staff_assignments')->updateOrInsert(
            ['branch_id' => (int) $branch->getKey(), 'user_id' => $userId],
            [
                'is_active' => true,
                'assigned_by_user_id' => (int) $actor->getKey(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function removeStaff(SmartOrderingBranch $branch, int $userId): void
    {
        $assignment = DB::table('restaurant_staff_assignments')
            ->where('branch_id', (int) $branch->getKey())
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        abort_unless($assignment !== null, 404);

        if (DB::table('restaurant_time_clock_sessions')
            ->where('branch_id', (int) $branch->getKey())
            ->where('user_id', $userId)
            ->whereIn('status', ['OPEN', 'ON_BREAK'])
            ->exists()) {
            throw ValidationException::withMessages([
                'user_id' => [__('smart_ordering.workforce.errors.active_clock')],
            ]);
        }

        DB::table('restaurant_staff_assignments')
            ->where('id', $assignment->id)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /** @param array<string,mixed> $data */
    public function createShift(SmartOrderingBranch $branch, array $data, User $actor): string
    {
        [$startsAt, $endsAt] = $this->shiftWindow($branch, $data);
        $userId = (int) $data['user_id'];
        $this->assertAssigned($branch, $userId);
        $this->assertNoOverlap($userId, $startsAt, $endsAt);

        $publicId = (string) Str::uuid();
        DB::table('restaurant_shifts')->insert([
            'public_id' => $publicId,
            'branch_id' => (int) $branch->getKey(),
            'user_id' => $userId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'DRAFT',
            'notes' => $this->nullableTrim($data['notes'] ?? null),
            'created_by_user_id' => (int) $actor->getKey(),
            'updated_by_user_id' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /** @param array<string,mixed> $data */
    public function updateShift(
        SmartOrderingBranch $branch,
        string $shiftPublicId,
        array $data,
        User $actor,
    ): void {
        $shift = $this->shift($branch, $shiftPublicId);
        if ($shift->status === 'CANCELLED') {
            throw ValidationException::withMessages([
                'shift' => [__('smart_ordering.workforce.errors.cancelled_shift')],
            ]);
        }

        [$startsAt, $endsAt] = $this->shiftWindow($branch, $data);
        $userId = (int) $data['user_id'];
        $this->assertAssigned($branch, $userId);
        $this->assertNoOverlap($userId, $startsAt, $endsAt, (int) $shift->id);

        DB::table('restaurant_shifts')
            ->where('id', $shift->id)
            ->update([
                'user_id' => $userId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notes' => $this->nullableTrim($data['notes'] ?? null),
                'updated_by_user_id' => (int) $actor->getKey(),
                'updated_at' => now(),
            ]);
    }

    public function transitionShift(
        SmartOrderingBranch $branch,
        string $shiftPublicId,
        string $action,
        User $actor,
    ): void {
        $shift = $this->shift($branch, $shiftPublicId);
        $target = match ($action) {
            'publish' => 'PUBLISHED',
            'cancel' => 'CANCELLED',
            default => null,
        };

        $allowed = match ((string) $shift->status) {
            'DRAFT' => ['PUBLISHED', 'CANCELLED'],
            'PUBLISHED' => ['CANCELLED'],
            default => [],
        };

        if ($target === null || ! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'action' => [__('smart_ordering.workforce.errors.invalid_shift_transition')],
            ]);
        }

        DB::table('restaurant_shifts')
            ->where('id', $shift->id)
            ->update([
                'status' => $target,
                'updated_by_user_id' => (int) $actor->getKey(),
                'updated_at' => now(),
            ]);
    }

    public function clock(
        SmartOrderingBranch $branch,
        User $actor,
        string $action,
        ?string $shiftPublicId = null,
    ): string {
        $this->assertAssigned($branch, (int) $actor->getKey());

        return DB::transaction(function () use ($branch, $actor, $action, $shiftPublicId): string {
            $active = DB::table('restaurant_time_clock_sessions')
                ->where('branch_id', (int) $branch->getKey())
                ->where('user_id', $actor->getKey())
                ->whereIn('status', ['OPEN', 'ON_BREAK'])
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($action === 'clock_in') {
                if ($active !== null) {
                    throw ValidationException::withMessages([
                        'action' => [__('smart_ordering.workforce.errors.already_clocked_in')],
                    ]);
                }

                $shiftId = null;
                if ($shiftPublicId !== null) {
                    $shift = $this->shift($branch, $shiftPublicId);
                    if ((int) $shift->user_id !== (int) $actor->getKey() || $shift->status !== 'PUBLISHED') {
                        throw ValidationException::withMessages([
                            'shift_public_id' => [__('smart_ordering.workforce.errors.shift_not_available')],
                        ]);
                    }
                    $shiftId = (int) $shift->id;
                }

                $publicId = (string) Str::uuid();
                DB::table('restaurant_time_clock_sessions')->insert([
                    'public_id' => $publicId,
                    'branch_id' => (int) $branch->getKey(),
                    'user_id' => (int) $actor->getKey(),
                    'shift_id' => $shiftId,
                    'status' => 'OPEN',
                    'clocked_in_at' => now(),
                    'total_break_seconds' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $publicId;
            }

            if ($active === null) {
                throw ValidationException::withMessages([
                    'action' => [__('smart_ordering.workforce.errors.not_clocked_in')],
                ]);
            }

            $now = now();
            if ($action === 'break_start' && $active->status === 'OPEN') {
                DB::table('restaurant_time_clock_sessions')->where('id', $active->id)->update([
                    'status' => 'ON_BREAK',
                    'break_started_at' => $now,
                    'updated_at' => $now,
                ]);

                return (string) $active->public_id;
            }

            if ($action === 'break_end' && $active->status === 'ON_BREAK' && $active->break_started_at !== null) {
                $seconds = Carbon::parse((string) $active->break_started_at)->diffInSeconds($now);
                DB::table('restaurant_time_clock_sessions')->where('id', $active->id)->update([
                    'status' => 'OPEN',
                    'break_started_at' => null,
                    'last_break_ended_at' => $now,
                    'total_break_seconds' => (int) $active->total_break_seconds + $seconds,
                    'updated_at' => $now,
                ]);

                return (string) $active->public_id;
            }

            if ($action === 'clock_out' && $active->status === 'OPEN') {
                DB::table('restaurant_time_clock_sessions')->where('id', $active->id)->update([
                    'status' => 'CLOSED',
                    'clocked_out_at' => $now,
                    'updated_at' => $now,
                ]);

                return (string) $active->public_id;
            }

            throw ValidationException::withMessages([
                'action' => [__('smart_ordering.workforce.errors.invalid_clock_transition')],
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public function correctSession(
        SmartOrderingBranch $branch,
        string $sessionPublicId,
        array $data,
        User $actor,
    ): array {
        $session = DB::table('restaurant_time_clock_sessions')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $sessionPublicId)
            ->first();
        abort_unless($session !== null, 404);

        if ($session->status !== 'CLOSED') {
            throw ValidationException::withMessages([
                'session' => [__('smart_ordering.workforce.errors.correction_closed_only')],
            ]);
        }

        $timezone = (string) ($branch->timezone ?: 'UTC');
        $clockedIn = $this->parseLocal((string) $data['clocked_in_at'], $timezone);
        $clockedOut = $this->parseLocal((string) $data['clocked_out_at'], $timezone);
        if ($clockedOut->lessThanOrEqualTo($clockedIn)) {
            throw ValidationException::withMessages([
                'clocked_out_at' => [__('smart_ordering.workforce.errors.clock_order')],
            ]);
        }

        $before = [
            'store_id' => (int) $branch->store_id,
            'branch_id' => (int) $branch->getKey(),
            'user_id' => (int) $session->user_id,
            'clocked_in_at' => (string) $session->clocked_in_at,
            'clocked_out_at' => (string) $session->clocked_out_at,
            'total_break_seconds' => (int) $session->total_break_seconds,
            'correction_note' => $session->correction_note,
        ];
        $after = [
            'store_id' => (int) $branch->store_id,
            'branch_id' => (int) $branch->getKey(),
            'user_id' => (int) $session->user_id,
            'clocked_in_at' => $clockedIn,
            'clocked_out_at' => $clockedOut,
            'total_break_seconds' => (int) $data['total_break_minutes'] * 60,
            'corrected_by_user_id' => (int) $actor->getKey(),
            'correction_note' => trim((string) $data['reason']),
        ];

        DB::table('restaurant_time_clock_sessions')
            ->where('id', $session->id)
            ->update([
                'clocked_in_at' => $clockedIn,
                'clocked_out_at' => $clockedOut,
                'total_break_seconds' => $after['total_break_seconds'],
                'corrected_by_user_id' => $after['corrected_by_user_id'],
                'correction_note' => $after['correction_note'],
                'updated_at' => now(),
            ]);

        return ['before' => $before, 'after' => $after];
    }

    /** @return array<string,mixed> */
    private function emptyWorkspace(): array
    {
        return [
            'selected_branch' => null,
            'timezone' => null,
            'can_manage' => false,
            'actor_assigned' => false,
            'available_staff' => [],
            'assignments' => [],
            'shifts' => [],
            'sessions' => [],
            'current_session' => null,
        ];
    }

    private function assertAssigned(SmartOrderingBranch $branch, int $userId): void
    {
        if (! DB::table('restaurant_staff_assignments')
            ->where('branch_id', (int) $branch->getKey())
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'user_id' => [__('smart_ordering.workforce.errors.not_assigned')],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0:Carbon,1:Carbon}
     */
    private function shiftWindow(SmartOrderingBranch $branch, array $data): array
    {
        $timezone = (string) ($branch->timezone ?: 'UTC');
        $startsAt = $this->parseLocal((string) $data['starts_at'], $timezone);
        $endsAt = $this->parseLocal((string) $data['ends_at'], $timezone);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => [__('smart_ordering.workforce.errors.shift_order')],
            ]);
        }

        return [$startsAt, $endsAt];
    }

    private function assertNoOverlap(
        int $userId,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreShiftId = null,
    ): void {
        $overlap = DB::table('restaurant_shifts')
            ->where('user_id', $userId)
            ->where('status', '!=', 'CANCELLED')
            ->when($ignoreShiftId !== null, fn ($query) => $query->where('id', '!=', $ignoreShiftId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => [__('smart_ordering.workforce.errors.shift_overlap')],
            ]);
        }
    }

    private function shift(SmartOrderingBranch $branch, string $publicId): object
    {
        $shift = DB::table('restaurant_shifts')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $publicId)
            ->first();
        abort_unless($shift !== null, 404);

        return $shift;
    }

    private function parseLocal(string $value, string $timezone): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d\TH:i', $value, $timezone)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'time' => [__('smart_ordering.workforce.errors.invalid_time')],
            ]);
        }
    }

    private function displayLocal(string $value, string $timezone): string
    {
        return Carbon::parse($value, 'UTC')->timezone($timezone)->format('Y-m-d H:i');
    }

    private function inputLocal(string $value, string $timezone): string
    {
        return Carbon::parse($value, 'UTC')->timezone($timezone)->format('Y-m-d\TH:i');
    }

    private function roleLabel(string $code, string $fallback): string
    {
        $key = 'admin.role_names.'.$code;
        $translated = __($key);

        return $translated === $key ? $fallback : $translated;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
