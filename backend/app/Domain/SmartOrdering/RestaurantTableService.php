<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Models\SmartOrderingQrCode;
use App\Models\User;
use App\Services\SmartOrderingQrManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Canonical tenant-scoped floor-plan and table-service operations for Restaurant SaaS W3 tables.
 */
final class RestaurantTableService
{
    public function __construct(
        private readonly SmartOrderingQrManager $qrManager,
    ) {}

    /**
     * @param Collection<int, SmartOrderingBranch> $branches
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
            return [
                'selected_branch' => null,
                'floors' => [],
                'unassigned_tables' => [],
                'tables' => [],
                'active_combinations' => [],
                'can_manage' => false,
                'can_manage_qr' => false,
            ];
        }

        $branchId = (int) $selected->getKey();
        $floors = DB::table('smart_ordering_floor_areas')
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $tables = DB::table('smart_ordering_tables as t')
            ->leftJoin('smart_ordering_floor_areas as f', 'f.id', '=', 't.floor_area_id')
            ->leftJoin('smart_ordering_qr_codes as qr', 'qr.id', '=', 't.qr_code_id')
            ->where('t.branch_id', $branchId)
            ->orderBy('f.sort_order')
            ->orderBy('t.label')
            ->get([
                't.*',
                'f.public_id as floor_public_id',
                'f.name_en as floor_name_en',
                'f.name_ar as floor_name_ar',
                'qr.public_id as qr_public_id',
                'qr.is_enabled as qr_is_enabled',
            ]);

        $directCounts = DB::table('smart_ordering_order_contexts')
            ->where('branch_id', $branchId)
            ->where('fulfillment_mode', 'dine_in')
            ->whereNotIn('lifecycle_status', ['COMPLETED', 'CANCELLED'])
            ->whereNotNull('table_id')
            ->groupBy('table_id')
            ->selectRaw('table_id, COUNT(*) as open_orders')
            ->pluck('open_orders', 'table_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $combinations = DB::table('smart_ordering_table_combinations as c')
            ->join('smart_ordering_tables as p', 'p.id', '=', 'c.primary_table_id')
            ->join('smart_ordering_tables as m', 'm.id', '=', 'c.member_table_id')
            ->where('c.branch_id', $branchId)
            ->whereNull('c.split_at')
            ->orderBy('c.combined_at')
            ->get([
                'c.public_id',
                'c.primary_table_id',
                'c.member_table_id',
                'c.combined_at',
                'c.note',
                'p.label as primary_label',
                'm.label as member_label',
            ]);

        $occupied = [];
        foreach (array_keys($directCounts) as $tableId) {
            $occupied[(int) $tableId] = true;
        }

        do {
            $changed = false;
            foreach ($combinations as $combination) {
                $primary = (int) $combination->primary_table_id;
                $member = (int) $combination->member_table_id;
                if (($occupied[$primary] ?? false) || ($occupied[$member] ?? false)) {
                    if (! ($occupied[$primary] ?? false) || ! ($occupied[$member] ?? false)) {
                        $changed = true;
                    }
                    $occupied[$primary] = true;
                    $occupied[$member] = true;
                }
            }
        } while ($changed);

        $combinationByTable = [];
        foreach ($combinations as $combination) {
            $combinationByTable[(int) $combination->primary_table_id] = [
                'public_id' => (string) $combination->public_id,
                'partner_label' => (string) $combination->member_label,
                'is_primary' => true,
                'note' => $combination->note,
            ];
            $combinationByTable[(int) $combination->member_table_id] = [
                'public_id' => (string) $combination->public_id,
                'partner_label' => (string) $combination->primary_label,
                'is_primary' => false,
                'note' => $combination->note,
            ];
        }

        $canManage = $actor->hasPermission('orders.manage', (int) $selected->store_id)
            || $actor->hasPermission('orders.manage');
        $canManageQr = $canManage
            && ($actor->hasPermission('smart_ordering.qr.manage', (int) $selected->store_id)
                || $actor->hasPermission('smart_ordering.qr.manage'));

        $rows = $tables->map(function (object $table) use (
            $actor,
            $selected,
            $directCounts,
            $occupied,
            $combinationByTable,
            $canManage,
            $canManageQr,
        ): array {
            $id = (int) $table->id;

            return [
                ...((array) $table),
                'open_orders' => (int) ($directCounts[$id] ?? 0),
                'occupancy' => ($occupied[$id] ?? false) ? 'occupied' : 'available',
                'combination' => $combinationByTable[$id] ?? null,
                'can_manage' => $canManage,
                'can_manage_qr' => $canManageQr,
                'store_id' => (int) $selected->store_id,
                'floor_name' => app()->getLocale() === 'ar'
                    ? ($table->floor_name_ar ?: $table->floor_name_en)
                    : ($table->floor_name_en ?: $table->floor_name_ar),
            ];
        });

        $floorRows = $floors->map(function (object $floor) use ($rows): array {
            return [
                ...((array) $floor),
                'name' => app()->getLocale() === 'ar'
                    ? ((string) $floor->name_ar ?: (string) $floor->name_en)
                    : ((string) $floor->name_en ?: (string) $floor->name_ar),
                'tables' => $rows->where('floor_area_id', (int) $floor->id)->values()->all(),
            ];
        })->values()->all();

        return [
            'selected_branch' => $selected,
            'floors' => $floorRows,
            'unassigned_tables' => $rows->whereNull('floor_area_id')->values()->all(),
            'tables' => $rows->values()->all(),
            'active_combinations' => $combinations->all(),
            'can_manage' => $canManage,
            'can_manage_qr' => $canManageQr,
        ];
    }

    /** @param array<string,mixed> $data */
    public function createFloor(SmartOrderingBranch $branch, array $data): void
    {
        DB::table('smart_ordering_floor_areas')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => (int) $branch->getKey(),
            'name_en' => trim((string) $data['name_en']),
            'name_ar' => trim((string) $data['name_ar']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function updateFloor(SmartOrderingBranch $branch, string $floorPublicId, array $data): void
    {
        $floor = $this->floor($branch, $floorPublicId);

        DB::table('smart_ordering_floor_areas')
            ->where('id', $floor->id)
            ->update([
                'name_en' => trim((string) $data['name_en']),
                'name_ar' => trim((string) $data['name_ar']),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'updated_at' => now(),
            ]);
    }

    public function deleteFloor(SmartOrderingBranch $branch, string $floorPublicId): void
    {
        $floor = $this->floor($branch, $floorPublicId);
        if (DB::table('smart_ordering_tables')->where('floor_area_id', $floor->id)->exists()) {
            throw ValidationException::withMessages([
                'floor' => [__('smart_ordering.tables.errors.floor_in_use')],
            ]);
        }

        DB::table('smart_ordering_floor_areas')->where('id', $floor->id)->delete();
    }

    /** @param array<string,mixed> $data */
    public function createTable(SmartOrderingBranch $branch, array $data): void
    {
        $this->assertUniqueLabel($branch, (string) $data['label']);

        DB::table('smart_ordering_tables')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => (int) $branch->getKey(),
            'floor_area_id' => $this->floorId($branch, $data['floor_public_id'] ?? null),
            'label' => trim((string) $data['label']),
            'capacity' => (int) $data['capacity'],
            'position_x' => (int) $data['position_x'],
            'position_y' => (int) $data['position_y'],
            'visual_width' => (int) $data['visual_width'],
            'visual_height' => (int) $data['visual_height'],
            'shape' => (string) $data['shape'],
            'qr_reference' => (string) Str::uuid(),
            'token_hash' => hash('sha256', (string) Str::uuid().Str::random(40)),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    public function updateTable(SmartOrderingBranch $branch, string $tablePublicId, array $data): void
    {
        $table = $this->table($branch, $tablePublicId);
        $this->assertUniqueLabel($branch, (string) $data['label'], (int) $table->id);

        DB::table('smart_ordering_tables')
            ->where('id', $table->id)
            ->update([
                'floor_area_id' => $this->floorId($branch, $data['floor_public_id'] ?? null),
                'label' => trim((string) $data['label']),
                'capacity' => (int) $data['capacity'],
                'position_x' => (int) $data['position_x'],
                'position_y' => (int) $data['position_y'],
                'visual_width' => (int) $data['visual_width'],
                'visual_height' => (int) $data['visual_height'],
                'shape' => (string) $data['shape'],
                'qr_reference' => $table->qr_reference ?: (string) Str::uuid(),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'updated_at' => now(),
            ]);

        if ($table->qr_code_id !== null) {
            $this->syncBoundQr($branch, $this->table($branch, $tablePublicId), (int) $table->qr_code_id);
        }
    }

    /** @param array<string,mixed> $data */
    public function updatePosition(SmartOrderingBranch $branch, string $tablePublicId, array $data): void
    {
        $table = $this->table($branch, $tablePublicId);

        DB::table('smart_ordering_tables')
            ->where('id', $table->id)
            ->update([
                'position_x' => (int) $data['position_x'],
                'position_y' => (int) $data['position_y'],
                'updated_at' => now(),
            ]);
    }

    public function deleteTable(SmartOrderingBranch $branch, string $tablePublicId): void
    {
        $table = $this->table($branch, $tablePublicId);
        $referenced = DB::table('smart_ordering_order_contexts')->where('table_id', $table->id)->exists();
        $combined = DB::table('smart_ordering_table_combinations')
            ->where('primary_table_id', $table->id)
            ->orWhere('member_table_id', $table->id)
            ->exists();

        if ($referenced || $combined) {
            throw ValidationException::withMessages([
                'table' => [__('smart_ordering.tables.errors.table_has_history')],
            ]);
        }

        if ($table->qr_code_id !== null) {
            $qr = SmartOrderingQrCode::query()->find((int) $table->qr_code_id);
            if ($qr instanceof SmartOrderingQrCode) {
                $this->qrManager->setEnabled($qr, false);
            }
        }

        DB::table('smart_ordering_tables')->where('id', $table->id)->delete();
    }

    public function bindQr(SmartOrderingBranch $branch, string $tablePublicId, User $actor): void
    {
        $table = $this->table($branch, $tablePublicId);
        if ($table->qr_reference === null) {
            $reference = (string) Str::uuid();
            DB::table('smart_ordering_tables')
                ->where('id', $table->id)
                ->update(['qr_reference' => $reference, 'updated_at' => now()]);
            $table->qr_reference = $reference;
        }

        if ($table->qr_code_id !== null) {
            $this->syncBoundQr($branch, $table, (int) $table->qr_code_id, (int) $actor->getKey());

            return;
        }

        $created = $this->qrManager->create([
            'store_id' => (int) $branch->store_id,
            'promotion_id' => null,
            'type' => 'table',
            'label' => __('smart_ordering.tables.qr_label', ['table' => (string) $table->label]),
            'notes' => __('smart_ordering.tables.qr_notes'),
            'target_path' => '/menu',
            'source' => 'restaurant-floor-plan',
            'medium' => 'qr',
            'campaign' => null,
            'branch_reference' => (string) $branch->code,
            'table_reference' => (string) $table->qr_reference,
            'is_enabled' => true,
            'valid_from' => null,
            'valid_until' => null,
        ], (int) $actor->getKey());

        DB::table('smart_ordering_tables')
            ->where('id', $table->id)
            ->update([
                'qr_code_id' => $created['qr']->getKey(),
                'qr_bound_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function unbindQr(SmartOrderingBranch $branch, string $tablePublicId, User $actor): void
    {
        $table = $this->table($branch, $tablePublicId);
        if ($table->qr_code_id !== null) {
            $qr = SmartOrderingQrCode::query()->find((int) $table->qr_code_id);
            if ($qr instanceof SmartOrderingQrCode) {
                $this->qrManager->setEnabled($qr, false, (int) $actor->getKey());
            }
        }

        DB::table('smart_ordering_tables')
            ->where('id', $table->id)
            ->update([
                'qr_code_id' => null,
                'qr_bound_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function combine(
        SmartOrderingBranch $branch,
        string $primaryPublicId,
        string $memberPublicId,
        User $actor,
        ?string $note = null,
    ): void {
        $primary = $this->table($branch, $primaryPublicId);
        $member = $this->table($branch, $memberPublicId);

        if ((int) $primary->id === (int) $member->id) {
            throw ValidationException::withMessages([
                'other_table_public_id' => [__('smart_ordering.tables.errors.combine_same')],
            ]);
        }
        if (! $primary->is_active || ! $member->is_active) {
            throw ValidationException::withMessages([
                'other_table_public_id' => [__('smart_ordering.tables.errors.combine_inactive')],
            ]);
        }

        $ids = [(int) $primary->id, (int) $member->id];
        $activeConflict = DB::table('smart_ordering_table_combinations')
            ->whereNull('split_at')
            ->where(function ($query) use ($ids): void {
                $query->whereIn('primary_table_id', $ids)->orWhereIn('member_table_id', $ids);
            })
            ->exists();

        if ($activeConflict) {
            throw ValidationException::withMessages([
                'other_table_public_id' => [__('smart_ordering.tables.errors.combine_busy')],
            ]);
        }

        DB::table('smart_ordering_table_combinations')->insert([
            'public_id' => (string) Str::uuid(),
            'branch_id' => (int) $branch->getKey(),
            'primary_table_id' => (int) $primary->id,
            'member_table_id' => (int) $member->id,
            'combined_by' => (int) $actor->getKey(),
            'split_by' => null,
            'combined_at' => now(),
            'split_at' => null,
            'note' => $note === null ? null : trim($note),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function split(SmartOrderingBranch $branch, string $combinationPublicId, User $actor): void
    {
        $combination = DB::table('smart_ordering_table_combinations')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $combinationPublicId)
            ->whereNull('split_at')
            ->first();

        abort_unless($combination !== null, 404);

        DB::table('smart_ordering_table_combinations')
            ->where('id', $combination->id)
            ->update([
                'split_by' => (int) $actor->getKey(),
                'split_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function syncBoundQr(
        SmartOrderingBranch $branch,
        object $table,
        int $qrId,
        ?int $actorId = null,
    ): void {
        $qr = SmartOrderingQrCode::query()->find($qrId);
        if (! $qr instanceof SmartOrderingQrCode) {
            DB::table('smart_ordering_tables')
                ->where('id', $table->id)
                ->update(['qr_code_id' => null, 'qr_bound_at' => null, 'updated_at' => now()]);

            return;
        }

        $this->qrManager->update($qr, [
            'promotion_id' => $qr->promotion_id,
            'type' => 'table',
            'label' => __('smart_ordering.tables.qr_label', ['table' => (string) $table->label]),
            'notes' => $qr->notes,
            'target_path' => $qr->target_path ?: '/menu',
            'source' => $qr->source,
            'medium' => $qr->medium,
            'campaign' => $qr->campaign,
            'branch_reference' => (string) $branch->code,
            'table_reference' => (string) $table->qr_reference,
            'valid_from' => $qr->valid_from,
            'valid_until' => $qr->valid_until,
        ], $actorId);
        $this->qrManager->setEnabled($qr, true, $actorId);
    }

    private function floor(SmartOrderingBranch $branch, string $publicId): object
    {
        $floor = DB::table('smart_ordering_floor_areas')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $publicId)
            ->first();

        abort_unless($floor !== null, 404);

        return $floor;
    }

    private function table(SmartOrderingBranch $branch, string $publicId): object
    {
        $table = DB::table('smart_ordering_tables')
            ->where('branch_id', (int) $branch->getKey())
            ->where('public_id', $publicId)
            ->first();

        abort_unless($table !== null, 404);

        return $table;
    }

    private function floorId(SmartOrderingBranch $branch, mixed $publicId): ?int
    {
        if (! is_string($publicId) || trim($publicId) === '') {
            return null;
        }

        return (int) $this->floor($branch, trim($publicId))->id;
    }

    private function assertUniqueLabel(
        SmartOrderingBranch $branch,
        string $label,
        ?int $exceptId = null,
    ): void {
        $query = DB::table('smart_ordering_tables')
            ->where('branch_id', (int) $branch->getKey())
            ->whereRaw('LOWER(label) = ?', [Str::lower(trim($label))]);

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'label' => [__('smart_ordering.tables.errors.label_taken')],
            ]);
        }
    }
}
