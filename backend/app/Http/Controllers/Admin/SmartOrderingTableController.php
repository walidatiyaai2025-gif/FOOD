<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantTableService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingTableController extends Controller
{
    public function __construct(
        private readonly RestaurantSaasStoreAccess $scope,
        private readonly RestaurantTableService $tables,
        private readonly AuditLogger $audit,
    ) {}

    public function storeFloor(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->createFloor($branchModel, $this->floorData($request));

        return $this->backToTables($branchModel, __('smart_ordering.tables.floor_saved'));
    }

    public function updateFloor(string $branch, string $floor, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->updateFloor($branchModel, $floor, $this->floorData($request));

        return $this->backToTables($branchModel, __('smart_ordering.tables.floor_saved'));
    }

    public function destroyFloor(string $branch, string $floor, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->deleteFloor($branchModel, $floor);

        return $this->backToTables($branchModel, __('smart_ordering.tables.floor_deleted'));
    }

    public function storeTable(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->createTable($branchModel, $this->tableData($request));

        return $this->backToTables($branchModel, __('smart_ordering.tables.table_saved'));
    }

    public function updateTable(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->updateTable($branchModel, $table, $this->tableData($request));

        return $this->backToTables($branchModel, __('smart_ordering.tables.table_saved'));
    }

    public function updatePosition(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $data = $request->validate([
            'position_x' => ['required', 'integer', 'between:0,1000'],
            'position_y' => ['required', 'integer', 'between:0,1000'],
        ]);
        $this->tables->updatePosition($branchModel, $table, $data);

        return $this->backToTables($branchModel, __('smart_ordering.tables.position_saved'));
    }

    public function destroyTable(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->deleteTable($branchModel, $table);

        return $this->backToTables($branchModel, __('smart_ordering.tables.table_deleted'));
    }

    public function bindQr(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->scope->assertStore(
            $actor,
            (int) $branchModel->store_id,
            'smart_ordering.qr.manage',
            'b2c',
        );
        $this->tables->bindQr($branchModel, $table, $actor);

        return $this->backToTables($branchModel, __('smart_ordering.tables.qr_bound'));
    }

    public function unbindQr(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->scope->assertStore(
            $actor,
            (int) $branchModel->store_id,
            'smart_ordering.qr.manage',
            'b2c',
        );
        $this->tables->unbindQr($branchModel, $table, $actor);

        return $this->backToTables($branchModel, __('smart_ordering.tables.qr_unbound'));
    }

    public function combine(string $branch, string $table, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $data = $request->validate([
            'other_table_public_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->tables->combine(
            $branchModel,
            $table,
            (string) $data['other_table_public_id'],
            $actor,
            isset($data['note']) ? (string) $data['note'] : null,
        );
        $this->audit->record('smart_ordering.tables.combined', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_public_id' => (string) $branchModel->public_id,
            'primary_table_public_id' => $table,
            'member_table_public_id' => (string) $data['other_table_public_id'],
            'note' => $data['note'] ?? null,
        ], $request);

        return $this->backToTables($branchModel, __('smart_ordering.tables.tables_combined'));
    }

    public function split(string $branch, string $combination, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->tables->split($branchModel, $combination, $actor);
        $this->audit->record('smart_ordering.tables.split', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_public_id' => (string) $branchModel->public_id,
            'combination_public_id' => $combination,
        ], $request);

        return $this->backToTables($branchModel, __('smart_ordering.tables.tables_split'));
    }

    /**
     * @return array{0:User,1:SmartOrderingBranch}
     */
    private function authorizedBranch(string $publicId, Request $request): array
    {
        $actor = $this->actor($request);
        $branch = SmartOrderingBranch::query()->where('public_id', $publicId)->firstOrFail();
        $this->scope->assertStore($actor, (int) $branch->store_id, 'orders.manage', 'b2c');

        return [$actor, $branch];
    }

    /** @return array<string,mixed> */
    private function floorData(Request $request): array
    {
        return $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function tableData(Request $request): array
    {
        return $request->validate([
            'floor_public_id' => ['nullable', 'uuid'],
            'label' => ['required', 'string', 'max:80'],
            'capacity' => ['required', 'integer', 'between:1,50'],
            'position_x' => ['required', 'integer', 'between:0,1000'],
            'position_y' => ['required', 'integer', 'between:0,1000'],
            'visual_width' => ['required', 'integer', 'between:80,500'],
            'visual_height' => ['required', 'integer', 'between:70,500'],
            'shape' => ['required', Rule::in(['rectangle', 'round'])],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->is_active, 403);

        return $actor;
    }

    private function backToTables(SmartOrderingBranch $branch, string $status): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.operations.index', [
            'tab' => 'tables',
            'branch' => $branch->public_id,
        ])->with('status', $status);
    }
}
