<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantWorkforceService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingWorkforceController extends Controller
{
    public function __construct(
        private readonly RestaurantSaasStoreAccess $scope,
        private readonly RestaurantWorkforceService $workforce,
        private readonly AuditLogger $audit,
    ) {}

    public function storeAssignment(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $this->workforce->assignStaff($branchModel, (int) $data['user_id'], $actor);
        $this->audit->record('smart_ordering.workforce.staff.assigned', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'user_id' => (int) $data['user_id'],
        ], $request);

        return $this->back($branchModel, __('smart_ordering.workforce.staff_saved'));
    }

    public function destroyAssignment(string $branch, int $user, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $this->workforce->removeStaff($branchModel, $user);
        $this->audit->record('smart_ordering.workforce.staff.unassigned', $actor, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'user_id' => $user,
        ], null, $request);

        return $this->back($branchModel, __('smart_ordering.workforce.staff_removed'));
    }

    public function storeShift(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $data = $this->shiftData($request);
        $publicId = $this->workforce->createShift($branchModel, $data, $actor);
        $this->audit->record('smart_ordering.workforce.shift.created', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'shift_public_id' => $publicId,
            'user_id' => (int) $data['user_id'],
        ], $request);

        return $this->back($branchModel, __('smart_ordering.workforce.shift_saved'));
    }

    public function updateShift(string $branch, string $shift, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $data = $this->shiftData($request);
        $this->workforce->updateShift($branchModel, $shift, $data, $actor);
        $this->audit->record('smart_ordering.workforce.shift.updated', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'shift_public_id' => $shift,
            'user_id' => (int) $data['user_id'],
        ], $request);

        return $this->back($branchModel, __('smart_ordering.workforce.shift_saved'));
    }

    public function transitionShift(string $branch, string $shift, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $data = $request->validate(['action' => ['required', Rule::in(['publish', 'cancel'])]]);
        $this->workforce->transitionShift($branchModel, $shift, (string) $data['action'], $actor);
        $this->audit->record('smart_ordering.workforce.shift.transitioned', $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'shift_public_id' => $shift,
            'action' => (string) $data['action'],
        ], $request);

        return $this->back($branchModel, __('smart_ordering.workforce.shift_transitioned'));
    }

    public function clock(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'orders.view');
        $data = $request->validate([
            'action' => ['required', Rule::in(['clock_in', 'break_start', 'break_end', 'clock_out'])],
            'shift_public_id' => ['nullable', 'uuid'],
        ]);
        $session = $this->workforce->clock(
            $branchModel,
            $actor,
            (string) $data['action'],
            isset($data['shift_public_id']) ? (string) $data['shift_public_id'] : null,
        );
        $this->audit->record('smart_ordering.workforce.time_clock.'.(string) $data['action'], $actor, null, null, [
            'store_id' => (int) $branchModel->store_id,
            'branch_id' => (int) $branchModel->getKey(),
            'session_public_id' => $session,
        ], $request);

        return $this->back($branchModel, __('smart_ordering.workforce.clock_saved'));
    }

    public function correctSession(
        string $branch,
        string $session,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request, 'smart_ordering.manage');
        $data = $request->validate([
            'clocked_in_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'clocked_out_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'total_break_minutes' => ['required', 'integer', 'between:0,1440'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $change = $this->workforce->correctSession($branchModel, $session, $data, $actor);
        $this->audit->record(
            'smart_ordering.workforce.time_clock.corrected',
            $actor,
            null,
            $change['before'],
            $change['after'],
            $request,
        );

        return $this->back($branchModel, __('smart_ordering.workforce.correction_saved'));
    }

    /**
     * @return array{0:User,1:SmartOrderingBranch}
     */
    private function authorizedBranch(string $publicId, Request $request, string $permission): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->is_active, 403);

        $branch = SmartOrderingBranch::query()->where('public_id', $publicId)->firstOrFail();
        $this->scope->assertStore($actor, (int) $branch->store_id, $permission, 'b2c');

        return [$actor, $branch];
    }

    /** @return array<string,mixed> */
    private function shiftData(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function back(SmartOrderingBranch $branch, string $status): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.operations.index', [
            'tab' => 'workforce',
            'branch' => $branch->public_id,
        ])->with('status', $status);
    }
}
