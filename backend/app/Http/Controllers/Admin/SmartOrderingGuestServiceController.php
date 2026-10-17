<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\RestaurantGuestService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SmartOrderingGuestServiceController extends Controller
{
    public function __construct(
        private readonly RestaurantSaasStoreAccess $scope,
        private readonly RestaurantGuestService $guests,
    ) {}

    public function storeReservation(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->guests->createReservation($branchModel, $this->reservationData($request), $actor);

        return $this->back($branchModel, 'reservations', __('smart_ordering.guest_service.reservation_saved'));
    }

    public function updateReservation(
        string $branch,
        string $reservation,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->guests->updateReservation(
            $branchModel,
            $reservation,
            $this->reservationData($request),
            $actor,
        );

        return $this->back($branchModel, 'reservations', __('smart_ordering.guest_service.reservation_saved'));
    }

    public function transitionReservation(
        string $branch,
        string $reservation,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['CONFIRMED', 'SEATED', 'COMPLETED', 'CANCELLED', 'NO_SHOW'])],
        ]);
        $this->guests->transitionReservation($branchModel, $reservation, (string) $data['status'], $actor);

        return $this->back($branchModel, 'reservations', __('smart_ordering.guest_service.status_saved'));
    }

    public function storeWaitlist(string $branch, Request $request): RedirectResponse
    {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->guests->createWaitlist($branchModel, $this->waitlistData($request), $actor);

        return $this->back($branchModel, 'waitlist', __('smart_ordering.guest_service.waitlist_saved'));
    }

    public function updateWaitlist(
        string $branch,
        string $entry,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $this->guests->updateWaitlist($branchModel, $entry, $this->waitlistData($request), $actor);

        return $this->back($branchModel, 'waitlist', __('smart_ordering.guest_service.waitlist_saved'));
    }

    public function transitionWaitlist(
        string $branch,
        string $entry,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['WAITING', 'CONTACTED', 'CANCELLED'])],
        ]);
        $this->guests->transitionWaitlist($branchModel, $entry, (string) $data['status'], $actor);

        return $this->back($branchModel, 'waitlist', __('smart_ordering.guest_service.status_saved'));
    }

    public function convertWaitlist(
        string $branch,
        string $entry,
        Request $request,
    ): RedirectResponse {
        [$actor, $branchModel] = $this->authorizedBranch($branch, $request);
        $data = $request->validate([
            'table_public_id' => ['nullable', 'uuid'],
            'seat_now' => ['required', 'boolean'],
        ]);
        $this->guests->convertWaitlist(
            $branchModel,
            $entry,
            isset($data['table_public_id']) ? (string) $data['table_public_id'] : null,
            (bool) $data['seat_now'],
            $actor,
        );

        return $this->back($branchModel, 'waitlist', __('smart_ordering.guest_service.converted'));
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
    private function reservationData(Request $request): array
    {
        return $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:60'],
            'guest_email' => ['nullable', 'email:rfc', 'max:190'],
            'party_size' => ['required', 'integer', 'between:1,50'],
            'service_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'table_public_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string,mixed> */
    private function waitlistData(Request $request): array
    {
        return $request->validate([
            'guest_name' => ['required', 'string', 'max:160'],
            'guest_phone' => ['nullable', 'string', 'max:60'],
            'guest_email' => ['nullable', 'email:rfc', 'max:190'],
            'party_size' => ['required', 'integer', 'between:1,50'],
            'quoted_wait_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'priority' => ['required', 'integer', 'between:0,10'],
            'priority_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless($actor->is_active, 403);

        return $actor;
    }

    private function back(SmartOrderingBranch $branch, string $tab, string $status): RedirectResponse
    {
        return redirect()->route('admin.smart-ordering.operations.index', [
            'tab' => $tab,
            'branch' => $branch->public_id,
        ])->with('status', $status);
    }
}
