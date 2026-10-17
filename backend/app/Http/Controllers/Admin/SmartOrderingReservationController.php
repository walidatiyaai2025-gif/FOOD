<?php

namespace App\Http\Controllers\Admin;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantGuestFlowService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingReservationController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly RestaurantGuestFlowService $guestFlow,
    ) {}

    public function storeReservation(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->context($branch, $request);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:64'],
            'guest_email' => ['nullable', 'email', 'max:190'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'service_at' => ['required', 'date'],
            'table_public_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->guestFlow->createReservation($branchModel, $actor, $data, $request);

        return $this->back($branchModel, 'reservations')
            ->with('status', __('smart_ordering.reservations.reservation_saved'));
    }

    public function transitionReservation(
        string $branch,
        string $reservation,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->context($branch, $request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['CONFIRMED', 'SEATED', 'COMPLETED', 'CANCELLED', 'NO_SHOW'])],
            'table_public_id' => ['nullable', 'uuid'],
        ]);

        $this->guestFlow->transitionReservation(
            $branchModel,
            $reservation,
            $actor,
            (string) $data['status'],
            isset($data['table_public_id']) ? (string) $data['table_public_id'] : null,
            $request,
        );

        return $this->back($branchModel, 'reservations')
            ->with('status', __('smart_ordering.reservations.reservation_transitioned'));
    }

    public function storeWaitlist(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->context($branch, $request);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:64'],
            'guest_email' => ['nullable', 'email', 'max:190'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'quoted_wait_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority_reason' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->guestFlow->createWaitlist($branchModel, $actor, $data, $request);

        return $this->back($branchModel, 'waitlist')
            ->with('status', __('smart_ordering.reservations.waitlist_saved'));
    }

    public function transitionWaitlist(
        string $branch,
        string $waitlist,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->context($branch, $request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['WAITING', 'NOTIFIED', 'SEATED', 'CANCELLED', 'NO_SHOW'])],
            'table_public_id' => ['nullable', 'uuid'],
        ]);

        $this->guestFlow->transitionWaitlist(
            $branchModel,
            $waitlist,
            $actor,
            (string) $data['status'],
            isset($data['table_public_id']) ? (string) $data['table_public_id'] : null,
            $request,
        );

        return $this->back($branchModel, 'waitlist')
            ->with('status', __('smart_ordering.reservations.waitlist_transitioned'));
    }

    public function convertWaitlist(
        string $branch,
        string $waitlist,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->context($branch, $request);
        $data = $request->validate([
            'conversion_key' => ['required', 'string', 'max:160'],
            'service_at' => ['required', 'date'],
            'table_public_id' => ['nullable', 'uuid'],
        ]);

        $this->guestFlow->convertWaitlist($branchModel, $waitlist, $actor, $data, $request);

        return $this->back($branchModel, 'waitlist')
            ->with('status', __('smart_ordering.reservations.waitlist_converted'));
    }

    /** @return array{User,SmartOrderingBranch} */
    private function context(string $branch, Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $branchModel = SmartOrderingBranch::query()
            ->with('site')
            ->where('public_id', $branch)
            ->firstOrFail();
        $this->scope->assertStore($actor, (int) $branchModel->store_id, 'orders.manage', 'b2c');

        return [$actor, $branchModel];
    }

    private function back(SmartOrderingBranch $branch, string $view): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.operations.index', [
            'tab' => 'reservations',
            'branch' => $branch->public_id,
            'foh_view' => $view,
        ]);
    }
}
